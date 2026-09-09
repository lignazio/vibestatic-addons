<?php
/**
 * Upload the generated site to BunnyCDN Edge Storage.
 *
 * The plan-driven loop — what to send, what to remove, what to record and when
 * — is `WP2Static\PlanDrivenDeployer` in the core. What is left here is the
 * transport: two HTTP verbs against one host.
 *
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    /**
     * The default Edge Storage endpoint, Falkenstein.
     *
     * Regional zones live at `<region>.storage.bunnycdn.com`. Upstream hard-coded
     * the default host, so a zone created in New York or Singapore was written
     * to through the wrong endpoint.
     */
    const HOST = 'storage.bunnycdn.com';

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null Injectable, so the upload logic can be exercised without
     *                  a BunnyCDN account on the other end.
     */
    private $client = null;

    /**
     * @var string
     */
    private $zone = '';

    /**
     * @var array<string, string>
     */
    private $headers = [];

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-bunnycdn';
    }

    protected function label() : string {
        return 'BunnyCDN deployment';
    }

    /**
     * A prefix inside the zone, or nothing for its root.
     */
    protected function root() : string {
        $prefix = trim( $this->options->get( 'bunnycdnRemotePath' ), '/' );

        return '' === $prefix ? '' : '/' . $prefix;
    }

    protected function connect() : bool {
        $this->zone = trim( $this->options->get( 'bunnycdnStorageZoneName' ) );
        $password = $this->options->plain( 'bunnycdnStorageZonePassword' );

        /*
         * Both, and named separately. Upstream logged one sentence listing four
         * settings whenever any of them was missing, and then carried on to
         * make the request anyway.
         */
        if ( '' === $this->zone ) {
            WsLog::l( 'BunnyCDN: the storage zone name is not set.' );

            return false;
        }

        if ( '' === $password ) {
            WsLog::l( 'BunnyCDN: the storage zone password is not set.' );

            return false;
        }

        $this->headers = [
            'AccessKey' => $password,
            'Accept' => 'application/json',
        ];

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => 'https://' . self::host( $this->options->get( 'bunnycdnStorageRegion' ) ) . '/',
                    'http_errors' => false,
                    'timeout' => 300,
                ]
            );
        }

        return true;
    }

    /**
     * The Edge Storage host for a region code.
     *
     * An empty region is the default endpoint; anything else is prefixed. The
     * code is filtered rather than trusted, because it is typed by hand into a
     * settings field and ends up in a hostname.
     *
     * @param string $region The zone's region code, or empty for the default.
     */
    public static function host( string $region ) : string {
        $region = strtolower( trim( $region ) );

        if ( '' === $region || ! preg_match( '/^[a-z0-9]{2,8}$/', $region ) ) {
            return self::HOST;
        }

        return $region . '.' . self::HOST;
    }

    /**
     * @param string $local       Absolute path of the file to send.
     * @param string $destination Where it goes, root included.
     */
    protected function put( string $local, string $destination ) : bool {
        $handle = fopen( $local, 'rb' );

        if ( false === $handle ) {
            WsLog::l( 'BunnyCDN could not open for upload: ' . $local );

            return false;
        }

        /*
         * A stream, not file_get_contents(). Upstream read every file fully
         * into memory before sending it, which on a site with a few large
         * videos in it is the memory limit rather than a slow deploy.
         */
        return $this->request( 'PUT', $destination, $handle );
    }

    /**
     * @param string $destination Where it is, root included.
     */
    protected function delete( string $destination ) : bool {
        return $this->request( 'DELETE', $destination );
    }

    /**
     * Directories are not removed, and there is nothing to remove.
     *
     * Edge Storage has no directory entries of its own: a path exists because a
     * file underneath it does. There is no empty directory left behind, and
     * nothing served for one — unlike a filesystem or an FTP server, where the
     * core's walk up the tree is doing real work.
     *
     * DELETE on a directory path in Edge Storage is recursive, so implementing
     * this the obvious way would delete files the plan never asked about.
     *
     * @param string $destination Where it would be, root included.
     */
    protected function removeDirectory( string $destination ) : bool {
        return false;
    }

    /**
     * One request against the zone, with the response actually checked.
     *
     * Upstream decoded the JSON body and treated any truthy value as success —
     * so a 401 answering `{"Message":"Unauthorized"}` decoded to a truthy
     * object and counted as an upload. That is how a deploy with a wrong key
     * reported that it had published the site.
     *
     * The option array is written out at each call rather than merged from a
     * variable, so its shape stays visible to static analysis: Guzzle declares
     * a precise shape for it, and `array<string, mixed>` matches none of it.
     *
     * @param string        $method HTTP verb.
     * @param string        $path   Root-relative path at the destination.
     * @param resource|null $body   The file to send, or null for no body.
     */
    private function request( string $method, string $path, $body = null ) : bool {
        if ( ! $this->client ) {
            return false;
        }

        $uri = rawurlencode( $this->zone ) . '/' . self::encodePath( $path );

        try {
            $response = null === $body
                ? $this->client->request( $method, $uri, [ 'headers' => $this->headers ] )
                : $this->client->request(
                    $method,
                    $uri,
                    [
                        'headers' => $this->headers,
                        'body' => $body,
                    ]
                );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'BunnyCDN request failed: ' . $exception->getMessage() );

            return false;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            return true;
        }

        /*
         * A DELETE for something that is not there has done its job: the plan
         * wants the path gone, and it is. Reporting it as a failure would leave
         * it in the deploy cache and try again on every future deploy.
         */
        if ( 404 === $status && 'DELETE' === $method ) {
            return true;
        }

        WsLog::l( sprintf( 'BunnyCDN answered %d for %s %s', $status, $method, $path ) );

        return false;
    }

    /**
     * Percent-encode a path without destroying it.
     *
     * Segment by segment, because `rawurlencode()` on the whole thing turns
     * every `/` into `%2F` and asks the API for one long filename.
     *
     * @param string $path The path to encode.
     */
    public static function encodePath( string $path ) : string {
        $segments = explode( '/', ltrim( $path, '/' ) );

        return implode( '/', array_map( 'rawurlencode', $segments ) );
    }
}
