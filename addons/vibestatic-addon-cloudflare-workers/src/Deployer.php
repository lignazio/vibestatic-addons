<?php
/**
 * Publish the generated site into a Cloudflare Workers KV namespace.
 *
 * **What was untangled first.** The add-on this replaces required
 * `leonstafford/wp2staticguzzle`, `leonstafford/wp2static` and `latte/latte`,
 * and declared `replace` on guzzlehttp/guzzle — that is, it insisted on the
 * abandoned upstream's own fork of the plugin and of its HTTP client, and told
 * Composer that installing it satisfied Guzzle for everything else in the
 * project. None of those three constraints can be met today. All three are
 * gone: the HTTP client is the core's prefixed Guzzle, the templating is the
 * shared SettingsPage, and the core is a runtime dependency rather than a
 * Composer one.
 *
 * **What it does.** Every path becomes a KV key holding the file's bytes, and a
 * second key `<path>_ct` holding its content type — the layout a Workers Sites
 * script reads. Writes go through the bulk endpoint, which takes many keys per
 * request; the add-on this replaces had a bulk path and a one-key-at-a-time
 * path, and the singular one issued two PUTs per file and then read
 * `$result->success` off the *second* response to decide whether the *first*
 * had worked.
 *
 * **Worth knowing before relying on this.** Cloudflare has moved static-site
 * hosting to Pages and to Workers static assets; KV-backed Workers Sites is the
 * older arrangement. This add-on still targets KV, because that is what an
 * existing worker script expects, but a new site is better served by Pages.
 *
 * @package WP2StaticCloudflareWorkers
 */

namespace WP2StaticCloudflareWorkers;

use WP2Static\Addon\Options;

use WP2Static\DeployCache;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer {

    const API = 'https://api.cloudflare.com/client/v4/';

    const NAMESPACE_KEY = 'wp2static-addon-cloudflare-workers';

    /**
     * How many keys go in one bulk request, and how many bytes.
     *
     * Two files make two keys — the contents and the content type — so the file
     * cap is half the key cap. The byte cap is below Cloudflare's documented
     * bulk limit with room for the base64 expansion, which is a third on top of
     * the file's real size.
     */
    const FILES_PER_REQUEST = 2000;

    const BYTES_PER_REQUEST = 60000000;

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null Injectable, so the upload logic can be exercised without
     *                  a Cloudflare account on the other end.
     */
    private $client = null;

    /**
     * @var string
     */
    private $account = '';

    /**
     * @var string
     */
    private $namespace_id = '';

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    public function deploy( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        if ( ! $this->connect() ) {
            return;
        }

        $plan = DeployCache::plan( self::NAMESPACE_KEY );

        WsLog::l( $plan->summary() );

        $sent = $this->write( $processed_site_path, $plan->toDeploy() );
        $removed = $this->remove( $plan->toDelete() );

        WsLog::l(
            sprintf(
                'Cloudflare Workers deployment complete: %d sent, %d removed.',
                $sent,
                $removed
            )
        );
    }

    private function connect() : bool {
        $this->account = trim( $this->options->get( 'cloudflareAccountID' ) );
        $this->namespace_id = trim( $this->options->get( 'cloudflareNamespaceID' ) );
        $token = $this->options->plain( 'cloudflareAPIToken' );

        /*
         * Named one by one. Upstream logged a single sentence listing all three
         * whenever any was missing, which tells the reader to re-check three
         * fields when one of them is the problem.
         */
        if ( '' === $this->account ) {
            WsLog::l( 'Cloudflare: the account ID is not set.' );

            return false;
        }

        if ( '' === $this->namespace_id ) {
            WsLog::l( 'Cloudflare: the KV namespace ID is not set.' );

            return false;
        }

        if ( '' === $token ) {
            WsLog::l( 'Cloudflare: the API token is not set.' );

            return false;
        }

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => self::API,
                    'http_errors' => false,
                    'timeout' => 300,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ],
                ]
            );
        }

        return true;
    }

    /**
     * Write the changed files, in bulk.
     *
     * @param string   $processed_site_path Local root.
     * @param string[] $paths               Root-relative paths to send.
     * @return int How many files actually landed.
     */
    private function write( string $processed_site_path, array $paths ) : int {
        $sent = 0;
        $batch = [];
        $batch_paths = [];
        $bytes = 0;

        foreach ( $paths as $path ) {
            $local = $processed_site_path . $path;

            $contents = is_file( $local ) ? file_get_contents( $local ) : false;

            if ( false === $contents ) {
                WsLog::l( 'Cloudflare could not read for upload: ' . $path );

                continue;
            }

            $key = $this->key( $path );

            $batch[] = [
                'key' => $key,
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API's own encoding, not obfuscation.
                'value' => base64_encode( $contents ),
                'base64' => true,
            ];

            $batch[] = [
                'key' => $key . '_ct',
                'value' => MimeTypes::forPath( $local ),
            ];

            $batch_paths[] = $path;
            $bytes += strlen( $contents );

            if ( count( $batch_paths ) < self::FILES_PER_REQUEST && $bytes < self::BYTES_PER_REQUEST ) {
                continue;
            }

            $sent += $this->flush( $batch, $batch_paths );

            $batch = [];
            $batch_paths = [];
            $bytes = 0;
        }

        return $sent + $this->flush( $batch, $batch_paths );
    }

    /**
     * Send one bulk batch and record what arrived.
     *
     * @param list<array<string, mixed>> $batch       Key-value pairs.
     * @param string[]                   $batch_paths The paths they came from.
     * @return int How many files landed.
     */
    private function flush( array $batch, array $batch_paths ) : int {
        if ( ! $batch ) {
            return 0;
        }

        $ok = $this->request(
            'PUT',
            'accounts/' . $this->account . '/storage/kv/namespaces/' . $this->namespace_id . '/bulk',
            $batch
        );

        if ( ! $ok ) {
            return 0;
        }

        /*
         * The cache is written only once Cloudflare has said yes, and once per
         * batch. Upstream added every file to the deploy cache regardless of
         * the answer, so a rejected batch was remembered as deployed and never
         * retried.
         */
        foreach ( $batch_paths as $path ) {
            DeployCache::addFile( $path, self::NAMESPACE_KEY );
        }

        return count( $batch_paths );
    }

    /**
     * Delete the keys of files that have left the site.
     *
     * Upstream had no deletion at all: the KV namespace kept every page the
     * site had ever had, and a worker serving from it went on serving them.
     *
     * @param string[] $paths Root-relative paths that have gone.
     * @return int How many were removed.
     */
    private function remove( array $paths ) : int {
        if ( ! $paths ) {
            return 0;
        }

        $removed = 0;

        foreach ( array_chunk( $paths, self::FILES_PER_REQUEST ) as $chunk ) {
            $keys = [];

            foreach ( $chunk as $path ) {
                $keys[] = $this->key( $path );
                $keys[] = $this->key( $path ) . '_ct';
            }

            if ( ! $this->request(
                'DELETE',
                'accounts/' . $this->account . '/storage/kv/namespaces/' . $this->namespace_id . '/bulk',
                $keys
            ) ) {
                continue;
            }

            DeployCache::rmPaths( $chunk, self::NAMESPACE_KEY );

            $removed += count( $chunk );
        }

        return $removed;
    }

    /**
     * The KV key for a path.
     *
     * `/about/index.html` becomes `/about/`, which is the URL a worker is asked
     * for. Upstream did this in its bulk path and not in its singular one, so
     * the two modes wrote different keys for the same file and switching
     * between them left the namespace holding both.
     *
     * @param string $path The root-relative path.
     */
    private function key( string $path ) : string {
        if ( ! $this->options->bool( 'cloudflareIndexAsDirectory' ) ) {
            return $path;
        }

        if ( '/index.html' === substr( $path, -11 ) ) {
            return substr( $path, 0, -10 );
        }

        return $path;
    }

    /**
     * One request, with the response actually checked.
     *
     * Cloudflare answers 200 with `{"success": false, "errors": [...]}` for
     * some failures, so both the status and the envelope are read. Upstream
     * read `$result->success` without checking that the body had decoded, so a
     * gateway error — which returns HTML, not JSON — surfaced as
     * `Attempt to read property "success" on null`.
     *
     * The Guzzle option array is written out here rather than passed in, so
     * its shape stays visible to static analysis: Guzzle declares a precise
     * shape for it, and `array<string, mixed>` matches none of it.
     *
     * @param string  $method HTTP verb.
     * @param string  $uri    Path under the API root.
     * @param mixed[] $json   The JSON body: key-value pairs, or a list of keys.
     */
    private function request( string $method, string $uri, array $json ) : bool {
        if ( ! $this->client ) {
            return false;
        }

        try {
            $response = $this->client->request( $method, $uri, [ 'json' => $json ] );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'Cloudflare request failed: ' . $exception->getMessage() );

            return false;
        }

        $status = $response->getStatusCode();

        /** @var mixed $body */
        $body = json_decode( (string) $response->getBody(), true );

        if ( $status >= 200 && $status < 300 && is_array( $body ) && true === ( $body['success'] ?? false ) ) {
            return true;
        }

        WsLog::l(
            sprintf(
                'Cloudflare answered %d%s',
                $status,
                self::firstError( $body )
            )
        );

        return false;
    }

    /**
     * The first message out of Cloudflare's error envelope, if there is one.
     *
     * @param mixed $body The decoded response body.
     */
    private static function firstError( $body ) : string {
        if ( ! is_array( $body ) || ! isset( $body['errors'] ) || ! is_array( $body['errors'] ) ) {
            return '.';
        }

        $first = reset( $body['errors'] );

        if ( ! is_array( $first ) || ! isset( $first['message'] ) || ! is_string( $first['message'] ) ) {
            return '.';
        }

        return ': ' . $first['message'];
    }
}
