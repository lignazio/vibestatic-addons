<?php
/**
 * BunnyCDN Edge Storage deployment.
 *
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-bunnycdn';
    }

    public function name() : string {
        return __( 'BunnyCDN', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Uploads the generated site to BunnyCDN Edge Storage and purges the pull zone.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-bunnycdn';
    }

    /**
     * The options, and what changed about them.
     *
     * **The storage password is asked for directly.** Upstream took only the
     * account API key and then, *in the constructor*, called
     * `GET /api/storagezone` to find the zone by name and read its password
     * off the response — so merely creating the object made a network request,
     * a wrong zone name surfaced as `Attempt to read property "Password" on
     * null`, and the deploy needed an account-wide key where a zone-scoped one
     * would do. A key that can only write one storage zone is a smaller thing
     * to lose than one that can administer the account.
     *
     * The account API key is still here, and is now optional: it is used for
     * the pull-zone purge and for nothing else.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_bunnycdn_options',
                [
                    'bunnycdnStorageZoneName' => [ 'string', '' ],
                    'bunnycdnStorageZonePassword' => [ 'password', '' ],
                    'bunnycdnStorageRegion' => [ 'string', '' ],
                    'bunnycdnRemotePath' => [ 'string', '' ],
                    'bunnycdnPullZoneID' => [ 'string', '' ],
                    'bunnycdnAccountAPIKey' => [ 'password', '' ],
                ]
            );
        }

        return $this->options;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function fields() : array {
        return [
            'bunnycdnStorageZoneName' => [
                __( 'Storage zone name', 'vibestatic' ),
                __( 'The name of the Edge Storage zone the site is uploaded to.', 'vibestatic' ),
            ],
            'bunnycdnStorageZonePassword' => [
                __( 'Storage zone password', 'vibestatic' ),
                __(
                    'The zone\'s FTP & API password, from its FTP & API Access panel. Stored encrypted.',
                    'vibestatic'
                ),
            ],
            'bunnycdnStorageRegion' => [
                __( 'Storage region code', 'vibestatic' ),
                __(
                    'The zone\'s region prefix, such as ny, la, sg, syd, uk, se, br or jh. Leave blank for the default Falkenstein region.',
                    'vibestatic'
                ),
            ],
            'bunnycdnRemotePath' => [
                __( 'Path within the zone', 'vibestatic' ),
                __( 'Optional subdirectory. Leave blank to publish at the zone root.', 'vibestatic' ),
            ],
            'bunnycdnPullZoneID' => [
                __( 'Pull zone ID', 'vibestatic' ),
                __(
                    'Optional. Set it, with the account API key below, to purge the CDN cache after a deploy.',
                    'vibestatic'
                ),
            ],
            'bunnycdnAccountAPIKey' => [
                __( 'Account API key', 'vibestatic' ),
                __(
                    'Optional, and used only for the cache purge. Uploads do not need it. Stored encrypted.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function registerHooks() : void {
        add_action( 'wp2static_post_deploy_trigger', [ $this, 'purgeCache' ], 15, 1 );

        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic bunnycdn', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }

    /**
     * Purge the pull zone once the files are up.
     *
     * Upstream this method was a comment: eighty lines of curl behind `//`,
     * with a live `error_log('calling cache purge')` above them. It logged that
     * it was purging and then did nothing, which is worse than not having the
     * feature — a stale CDN looks like a failed deploy.
     *
     * @param string $enabled_deployer Slug of the deployer that just ran.
     */
    public function purgeCache( string $enabled_deployer = '' ) : void {
        if ( $this->slug() !== $enabled_deployer ) {
            return;
        }

        ( new Purger( $this->options() ) )->purge();
    }
}
