<?php
/**
 * Upload the generated site to Azure Blob Storage.
 *
 * The plan-driven loop is `WP2Static\PlanDrivenDeployer` in the core; here is
 * the transport, which is two signed HTTP requests. See Signer for why there is
 * no SDK.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure;

use WP2Static\Addon\Options;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null Injectable, so the upload logic can be exercised without
     *                  an Azure account on the other end.
     */
    private $client = null;

    /**
     * @var Signer|null
     */
    private $signer = null;

    /**
     * @var string
     */
    private $account = '';

    /**
     * @var string
     */
    private $container = '';

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-azure';
    }

    protected function label() : string {
        return 'Azure deployment';
    }

    /**
     * A prefix inside the container, or nothing for its root.
     */
    protected function root() : string {
        $prefix = trim( $this->options->get( 'azureRemotePath' ), '/' );

        return '' === $prefix ? '' : '/' . $prefix;
    }

    protected function connect() : bool {
        $this->account = trim( $this->options->get( 'azureAccountName' ) );
        $key = $this->options->plain( 'azureAccountKey' );

        if ( '' === $this->account ) {
            WsLog::l( 'Azure: the storage account name is not set.' );

            return false;
        }

        if ( '' === $key ) {
            WsLog::l( 'Azure: the account key is not set.' );

            return false;
        }

        $signer = new Signer( $this->account, $key );

        if ( ! $signer->isUsable() ) {
            WsLog::l( 'Azure: the account key is not valid base64. Copy it whole from the portal.' );

            return false;
        }

        $this->signer = $signer;

        $this->container = trim( $this->options->get( 'azureContainer' ) );

        if ( '' === $this->container ) {
            // `$web` is the container Azure serves a static website from, so it
            // is the useful default rather than an arbitrary one.
            $this->container = '$web';
        }

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => 'https://' . $this->account . '.' . $this->suffix() . '/',
                    'http_errors' => false,
                    'timeout' => 300,
                ]
            );
        }

        return true;
    }

    /**
     * The blob endpoint suffix.
     *
     * Configurable because the public cloud is not the only one: Azure China,
     * Azure Government and the sovereign clouds each serve blobs from their own
     * domain, and hard-coding one of them is what makes an add-on unusable in
     * the others.
     */
    private function suffix() : string {
        $suffix = trim( $this->options->get( 'azureEndpointSuffix' ) );

        if ( '' === $suffix || ! preg_match( '/^[a-z0-9.-]+$/', $suffix ) ) {
            return 'blob.core.windows.net';
        }

        return $suffix;
    }

    protected function put( string $local, string $destination ) : bool {
        $size = filesize( $local );

        if ( false === $size ) {
            WsLog::l( 'Azure could not size for upload: ' . $local );

            return false;
        }

        $handle = fopen( $local, 'rb' );

        if ( false === $handle ) {
            WsLog::l( 'Azure could not open for upload: ' . $local );

            return false;
        }

        $headers = [
            'Content-Length' => (string) $size,
            'Content-Type' => MimeTypes::forPath( $local ),
            'x-ms-blob-type' => 'BlockBlob',
            // The type is stored on the blob as well as sent with the request:
            // Azure serves back what it was told at upload time, and a page
            // stored as application/octet-stream is one the browser offers to
            // download instead of rendering.
            'x-ms-blob-content-type' => MimeTypes::forPath( $local ),
        ];

        $cache_control = trim( $this->options->get( 'azureCacheControl' ) );

        if ( '' !== $cache_control ) {
            $headers['x-ms-blob-cache-control'] = $cache_control;
        }

        return $this->request( 'PUT', $destination, $headers, $handle );
    }

    protected function delete( string $destination ) : bool {
        return $this->request( 'DELETE', $destination, [ 'Content-Length' => '0' ] );
    }

    /**
     * There are no directories in a container, so there are none to remove.
     *
     * @param string $destination Where it would be, root included.
     */
    protected function removeDirectory( string $destination ) : bool {
        return false;
    }

    /**
     * One signed request, with the response checked.
     *
     * The option array is written out at each call rather than merged from a
     * variable, so its shape stays visible to static analysis: Guzzle declares
     * a precise shape for it, and `array<string, mixed>` matches none of it.
     *
     * @param string                $method  HTTP verb.
     * @param string                $path    Root-relative path at the destination.
     * @param array<string, string> $headers Request headers, before signing.
     * @param resource|null         $body    The file to send, or null for none.
     */
    private function request( string $method, string $path, array $headers, $body = null ) : bool {
        if ( ! $this->client || ! $this->signer ) {
            return false;
        }

        $resource = '/' . $this->container . '/' . ltrim( $path, '/' );

        $headers['x-ms-date'] = gmdate( 'D, d M Y H:i:s \G\M\T' );
        $headers['x-ms-version'] = Signer::VERSION;

        $headers['Authorization'] = $this->signer->authorization( $method, $resource, $headers );

        /*
         * The container name is not put through the encoder. Azure's static
         * website container is literally called `$web`, and rawurlencode turns
         * `$` into `%24`: only the blob name is encoded, which is the part that
         * comes from a page title and can hold anything.
         */
        $uri = '/' . $this->container . self::encodePath( ltrim( $path, '/' ) );

        try {
            $response = null === $body
                ? $this->client->request( $method, $uri, [ 'headers' => $headers ] )
                : $this->client->request(
                    $method,
                    $uri,
                    [
                        'headers' => $headers,
                        'body' => $body,
                    ]
                );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'Azure request failed: ' . $exception->getMessage() );

            return false;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            return true;
        }

        // Already gone is the outcome a delete wanted.
        if ( 404 === $status && 'DELETE' === $method ) {
            return true;
        }

        WsLog::l( sprintf( 'Azure answered %d for %s %s', $status, $method, $path ) );

        return false;
    }

    /**
     * Percent-encode a path segment by segment.
     *
     * The signature is built from the *unencoded* resource and the request is
     * sent to the encoded one, which is what Azure expects and the easiest
     * thing in the whole exercise to get backwards.
     *
     * @param string $path The blob path, without the container.
     */
    public static function encodePath( string $path ) : string {
        $segments = explode( '/', ltrim( $path, '/' ) );

        return '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
    }
}
