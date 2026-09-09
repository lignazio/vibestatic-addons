<?php
/**
 * A read-only Algolia client, for looking at what got indexed.
 *
 * **No SDK.** Upstream required `algolia/algoliasearch-client-php ^2.6`, which
 * is three majors behind and does not install on PHP 8.2. What the add-on used
 * it for is two GETs and a paged POST, so those are written out here — the same
 * call the core made for S3 and this fork made for GCS and Azure.
 *
 * The credentials are read from the WP Search with Algolia plugin's own
 * options rather than duplicated into this add-on's settings: two places to
 * type the same admin key is two places for them to disagree.
 *
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia;

use WP2Static\Vendor\GuzzleHttp\Client as HttpClient;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Client {

    /**
     * The index WP Search with Algolia puts searchable posts in.
     */
    const DEFAULT_INDEX = 'wp_searchable_posts';

    /**
     * @var string
     */
    private $app_id;

    /**
     * @var string
     */
    private $api_key;

    /**
     * @var HttpClient|null
     */
    private $client;

    private function __construct( string $app_id, string $api_key, ?HttpClient $client = null ) {
        $this->app_id = $app_id;
        $this->api_key = $api_key;
        $this->client = $client;
    }

    /**
     * Build a client from the Algolia plugin's stored credentials.
     *
     * @param HttpClient|null $client Injectable for tests.
     */
    public static function fromPluginOptions( ?HttpClient $client = null ) : ?self {
        // get_option() answers whatever is in the row, so the type is checked
        // rather than cast: an option holding an array would otherwise become
        // the word "Array" and be sent as an application ID.
        $app_id = get_option( 'algolia_application_id', '' );
        $api_key = get_option( 'algolia_api_key', '' );

        if ( ! is_string( $app_id ) || ! is_string( $api_key ) ) {
            return null;
        }

        if ( '' === $app_id || '' === $api_key ) {
            WsLog::l(
                'Algolia: no credentials found. They come from the WP Search with Algolia plugin, which does not look installed.'
            );

            return null;
        }

        return new self( $app_id, $api_key, $client );
    }

    /**
     * The names of the indices in this application.
     *
     * @return string[]
     */
    public function indices() : array {
        $body = $this->request( 'GET', '1/indexes' );

        if ( null === $body || ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
            return [];
        }

        $names = [];

        foreach ( $body['items'] as $item ) {
            if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
                $names[] = $item['name'];
            }
        }

        return $names;
    }

    /**
     * Every object in an index, paged through.
     *
     * Upstream returned four fixed keys per hit and read
     * `$hit['post_author']['user_url']` without checking it was there — so one
     * record indexed by a plugin version that did not store the author killed
     * the whole listing. The record is returned as it is instead, and the
     * caller decides what to look at.
     *
     * @param string $index Index name; the searchable-posts one by default.
     * @return list<mixed[]>
     */
    public function objects( string $index = self::DEFAULT_INDEX ) : array {
        $objects = [];
        $cursor = null;

        // A hard stop, because the loop's exit depends on what the API returns:
        // a cursor that never empties would otherwise run until the time limit.
        for ( $page = 0; $page < 1000; $page++ ) {
            $body = $this->request(
                'POST',
                '1/indexes/' . rawurlencode( $index ) . '/browse',
                null === $cursor ? [] : [ 'cursor' => $cursor ]
            );

            if ( null === $body ) {
                break;
            }

            if ( isset( $body['hits'] ) && is_array( $body['hits'] ) ) {
                foreach ( $body['hits'] as $hit ) {
                    if ( is_array( $hit ) ) {
                        $objects[] = $hit;
                    }
                }
            }

            if ( ! isset( $body['cursor'] ) || ! is_string( $body['cursor'] ) ) {
                break;
            }

            $cursor = $body['cursor'];
        }

        return $objects;
    }

    /**
     * @param string                    $method HTTP verb.
     * @param string                    $uri    Path under the API root.
     * @param array<string, mixed>|null $body   JSON body, or null for none.
     * @return mixed[]|null
     */
    private function request( string $method, string $uri, ?array $body = null ) : ?array {
        $client = $this->client ?? new HttpClient(
            [
                // The -dsn host is the read-only, geo-distributed endpoint,
                // which is the right one for everything this class does.
                'base_uri' => 'https://' . $this->app_id . '-dsn.algolia.net/',
                'http_errors' => false,
                'timeout' => 60,
            ]
        );

        $options = [
            'headers' => [
                'X-Algolia-Application-Id' => $this->app_id,
                'X-Algolia-API-Key' => $this->api_key,
                'Accept' => 'application/json',
            ],
        ];

        if ( null !== $body ) {
            $options['json'] = $body;
        }

        try {
            $response = $client->request( $method, $uri, $options );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'Algolia request failed: ' . $exception->getMessage() );

            return null;
        }

        if ( 200 !== $response->getStatusCode() ) {
            WsLog::l(
                sprintf( 'Algolia answered %d for %s %s', $response->getStatusCode(), $method, $uri )
            );

            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode( (string) $response->getBody(), true );

        return is_array( $decoded ) ? $decoded : null;
    }
}
