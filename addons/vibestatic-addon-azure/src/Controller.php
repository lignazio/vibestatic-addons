<?php
/**
 * Azure Blob Storage deployment.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-azure';
    }

    public function name() : string {
        return __( 'Azure Blob Storage', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Uploads the generated site to an Azure Blob Storage container, and removes from it what has left the site.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-azure';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_azure_options',
                [
                    'azureAccountName' => [ 'string', '' ],
                    'azureAccountKey' => [ 'password', '' ],
                    'azureContainer' => [ 'string', '$web' ],
                    'azureRemotePath' => [ 'string', '' ],
                    'azureCacheControl' => [ 'string', '' ],
                    'azureEndpointSuffix' => [ 'string', '' ],
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
            'azureAccountName' => [
                __( 'Storage account name', 'vibestatic' ),
                __( 'The account the container belongs to.', 'vibestatic' ),
            ],
            'azureAccountKey' => [
                __( 'Account key', 'vibestatic' ),
                __(
                    'From Access keys in the portal. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'azureContainer' => [
                __( 'Container', 'vibestatic' ),
                __(
                    'Leave it as $web to publish through the account\'s static website endpoint.',
                    'vibestatic'
                ),
            ],
            'azureRemotePath' => [
                __( 'Path within the container', 'vibestatic' ),
                __( 'Optional prefix. Leave blank to publish at the container root.', 'vibestatic' ),
            ],
            'azureCacheControl' => [
                __( 'Cache-Control header', 'vibestatic' ),
                __( 'Optional, such as public, max-age=3600. Stored on each blob.', 'vibestatic' ),
            ],
            'azureEndpointSuffix' => [
                __( 'Endpoint suffix', 'vibestatic' ),
                __(
                    'Leave blank for the public cloud. Set it for a sovereign cloud, such as core.chinacloudapi.cn.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic azure', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
