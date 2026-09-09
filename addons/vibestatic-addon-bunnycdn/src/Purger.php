<?php
/**
 * Purge the BunnyCDN pull zone after a deploy.
 *
 * Separate from the Deployer because it talks to a different host with a
 * different credential: the account API at api.bunny.net, with the account key,
 * rather than Edge Storage with the zone password.
 *
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN;

use WP2Static\Addon\Options;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Purger {

    /**
     * bunny.net, not bunnycdn.com.
     *
     * Upstream pointed at `https://bunnycdn.com/api/`, the endpoint from before
     * the account API moved.
     */
    const API = 'https://api.bunny.net/';

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null
     */
    private $client = null;

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    /**
     * Purge, if there is enough configuration to do it.
     *
     * Silent when the pull zone is not configured: purging is optional, and a
     * warning on every deploy for a feature the user did not ask for is noise.
     */
    public function purge() : void {
        $zone_id = trim( $this->options->get( 'bunnycdnPullZoneID' ) );
        $api_key = $this->options->plain( 'bunnycdnAccountAPIKey' );

        if ( '' === $zone_id && '' === $api_key ) {
            return;
        }

        if ( '' === $zone_id || '' === $api_key ) {
            WsLog::l(
                'BunnyCDN: the cache purge needs both the pull zone ID and the account API key. Skipping it.'
            );

            return;
        }

        if ( ! preg_match( '/^[0-9]+$/', $zone_id ) ) {
            WsLog::l( 'BunnyCDN: the pull zone ID must be a number.' );

            return;
        }

        $client = $this->client ?? new Client(
            [
                'base_uri' => self::API,
                'http_errors' => false,
                'timeout' => 60,
            ]
        );

        try {
            $response = $client->request(
                'POST',
                'pullzone/' . $zone_id . '/purgeCache',
                [
                    'headers' => [
                        'AccessKey' => $api_key,
                        'Accept' => 'application/json',
                        'Content-Length' => '0',
                    ],
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'BunnyCDN cache purge failed: ' . $exception->getMessage() );

            return;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            WsLog::l( 'BunnyCDN pull zone cache purged.' );

            return;
        }

        WsLog::l( sprintf( 'BunnyCDN cache purge answered %d.', $status ) );
    }
}
