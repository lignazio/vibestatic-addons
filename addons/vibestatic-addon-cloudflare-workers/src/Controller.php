<?php
/**
 * Cloudflare Workers KV deployment.
 *
 * @package WP2StaticCloudflareWorkers
 */

namespace WP2StaticCloudflareWorkers;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-cloudflare-workers';
    }

    public function name() : string {
        return __( 'Cloudflare Workers KV', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Writes the generated site into a Workers KV namespace for a worker to serve.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-cloudflare-workers';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_cloudflare_workers_options',
                [
                    'cloudflareAccountID' => [ 'string', '' ],
                    'cloudflareNamespaceID' => [ 'string', '' ],
                    'cloudflareAPIToken' => [ 'password', '' ],
                    'cloudflareIndexAsDirectory' => [ 'bool', '1' ],
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
            'cloudflareAccountID' => [
                __( 'Account ID', 'vibestatic' ),
                __( 'From the sidebar of the Workers & Pages overview.', 'vibestatic' ),
            ],
            'cloudflareNamespaceID' => [
                __( 'KV namespace ID', 'vibestatic' ),
                __( 'The namespace the site is written into. It has to exist already.', 'vibestatic' ),
            ],
            'cloudflareAPIToken' => [
                __( 'API token', 'vibestatic' ),
                __(
                    'A token with Workers KV Storage: Edit on this account. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'cloudflareIndexAsDirectory' => [
                __( 'Store /page/index.html as /page/', 'vibestatic' ),
                __(
                    'What a worker asks KV for when a browser requests a directory URL. Turn it off only if your worker looks the full filename up.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'Cloudflare has since moved static hosting to Pages and to Workers static assets. This add-on still targets KV, which is what an existing Workers Sites script reads.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic cloudflare-workers', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
