<?php
/**
 * Google Cloud Storage deployment.
 *
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-gcs';
    }

    public function name() : string {
        return __( 'Google Cloud Storage', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Uploads the generated site to a Google Cloud Storage bucket, and removes from it what has left the site.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-gcs';
    }

    /**
     * Not yet, and the settings page says so.
     *
     * The requests below are written to the documented API and the logic is
     * covered by the tests, but no deploy has been watched against a real
     * account. This line becomes `true` when one has.
     */
    public function fieldTested() : bool {
        return false;
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_gcs_options',
                [
                    'gcsBucket' => [ 'string', '' ],
                    'gcsServiceAccountKey' => [ 'password', '' ],
                    'gcsRemotePath' => [ 'string', '' ],
                    'gcsPredefinedACL' => [ 'string', '' ],
                    'gcsCacheControl' => [ 'string', '' ],
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
            'gcsBucket' => [
                __( 'Bucket', 'vibestatic' ),
                __( 'The bucket the site is uploaded to.', 'vibestatic' ),
            ],
            'gcsServiceAccountKey' => [
                __( 'Service account key', 'vibestatic' ),
                __(
                    'The JSON key file, pasted whole. It needs Storage Object Admin on the bucket. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'gcsRemotePath' => [
                __( 'Path within the bucket', 'vibestatic' ),
                __( 'Optional prefix. Leave blank to publish at the bucket root.', 'vibestatic' ),
            ],
            'gcsPredefinedACL' => [
                __( 'Predefined ACL', 'vibestatic' ),
                __(
                    'Optional, such as publicRead. Leave blank on a bucket with uniform access, where per-object ACLs are refused.',
                    'vibestatic'
                ),
            ],
            'gcsCacheControl' => [
                __( 'Cache-Control header', 'vibestatic' ),
                __( 'Optional, such as public, max-age=3600. Applied to every uploaded object.', 'vibestatic' ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'The key is pasted here rather than left on the server as a file path: the old add-on read it from a path typed into this page, which broke whenever the site moved.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic gcs', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
