<?php
/**
 * Upload the generated site to Google Cloud Storage.
 *
 * The plan-driven loop is `WP2Static\PlanDrivenDeployer` in the core; here is
 * the transport, which is the JSON API and a bearer token. See ServiceAccount
 * for why there is no SDK.
 *
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS;

use WP2Static\Addon\Options;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    const API = 'https://storage.googleapis.com/';

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null Injectable, so the upload logic can be exercised without
     *                  a Google account on the other end.
     */
    private $client = null;

    /**
     * @var string
     */
    private $bucket = '';

    /**
     * @var string
     */
    private $token = '';

    /**
     * @var string Applied to every object, or empty for the bucket default.
     */
    private $predefined_acl = '';

    /**
     * @var string
     */
    private $cache_control = '';

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-gcs';
    }

    protected function label() : string {
        return 'GCS deployment';
    }

    /**
     * A prefix inside the bucket, or nothing for its root.
     */
    protected function root() : string {
        $prefix = trim( $this->options->get( 'gcsRemotePath' ), '/' );

        return '' === $prefix ? '' : '/' . $prefix;
    }

    protected function connect() : bool {
        $this->bucket = trim( $this->options->get( 'gcsBucket' ) );

        if ( '' === $this->bucket ) {
            WsLog::l( 'GCS: the bucket is not set.' );

            return false;
        }

        $key = $this->options->plain( 'gcsServiceAccountKey' );

        if ( '' === $key ) {
            WsLog::l( 'GCS: the service account key is not set.' );

            return false;
        }

        $account = ServiceAccount::fromJSON( $key, $this->client );

        if ( null === $account ) {
            return false;
        }

        $token = $account->accessToken();

        if ( null === $token ) {
            return false;
        }

        $this->token = $token;
        $this->predefined_acl = trim( $this->options->get( 'gcsPredefinedACL' ) );
        $this->cache_control = trim( $this->options->get( 'gcsCacheControl' ) );

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => self::API,
                    'http_errors' => false,
                    'timeout' => 300,
                ]
            );
        }

        WsLog::l( 'GCS: authenticated as ' . $account->email() );

        return true;
    }

    /**
     * @param string $local       Absolute path of the file to send.
     * @param string $destination Where it goes, root included.
     */
    protected function put( string $local, string $destination ) : bool {
        $handle = fopen( $local, 'rb' );

        if ( false === $handle ) {
            WsLog::l( 'GCS could not open for upload: ' . $local );

            return false;
        }

        $object = ltrim( $destination, '/' );

        $query = [
            'uploadType' => 'media',
            'name' => $object,
        ];

        if ( '' !== $this->predefined_acl ) {
            $query['predefinedAcl'] = $this->predefined_acl;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => MimeTypes::forPath( $local ),
        ];

        if ( '' !== $this->cache_control ) {
            $headers['Cache-Control'] = $this->cache_control;
        }

        if ( ! $this->client ) {
            return false;
        }

        try {
            $response = $this->client->request(
                'POST',
                'upload/storage/v1/b/' . rawurlencode( $this->bucket ) . '/o',
                [
                    'query' => $query,
                    'headers' => $headers,
                    'body' => $handle,
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'GCS request failed: ' . $exception->getMessage() );

            return false;
        }

        return $this->check( $response->getStatusCode(), 'POST', $destination );
    }

    /**
     * Remove one object that has left the site.
     *
     * **This is the whole reason for rewriting the add-on rather than patching
     * it.** Upstream had no deletion at all: it walked the processed site and
     * uploaded what the deploy cache did not know about, which finds new and
     * changed files and can never find a page that WordPress no longer has.
     * A post deleted in WordPress stayed served from the bucket for good.
     *
     * @param string $destination Where it is, root included.
     */
    protected function delete( string $destination ) : bool {
        if ( ! $this->client ) {
            return false;
        }

        try {
            $response = $this->client->request(
                'DELETE',
                'storage/v1/b/' . rawurlencode( $this->bucket ) . '/o/'
                    . rawurlencode( ltrim( $destination, '/' ) ),
                [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->token ] ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'GCS request failed: ' . $exception->getMessage() );

            return false;
        }

        return $this->check( $response->getStatusCode(), 'DELETE', $destination );
    }

    /**
     * There are no directories in a bucket, so there are none to remove.
     *
     * GCS object names contain slashes; the folders are a display convention in
     * the console, not entries that can be left behind empty.
     *
     * @param string $destination Where it would be, root included.
     */
    protected function removeDirectory( string $destination ) : bool {
        return false;
    }

    /**
     * Whether a response says the request worked.
     *
     * The option arrays are written out at each call rather than passed through
     * here, so their shape stays visible to static analysis: Guzzle declares a
     * precise shape for them and `array<string, mixed>` matches none of it.
     * What is left shared is the part worth sharing — the decision.
     *
     * @param int    $status      The HTTP status that came back.
     * @param string $method      HTTP verb, for the log and the rule below.
     * @param string $description What to name in the log.
     */
    private function check( int $status, string $method, string $description ) : bool {
        if ( $status >= 200 && $status < 300 ) {
            return true;
        }

        // Already gone is the outcome a delete wanted.
        if ( 404 === $status && 'DELETE' === $method ) {
            return true;
        }

        WsLog::l( sprintf( 'GCS answered %d for %s %s', $status, $method, $description ) );

        return false;
    }
}
