<?php
/**
 * Bitbucket deployment.
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-bitbucket';
    }

    public function name() : string {
        return __( 'Bitbucket', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Commits the generated site to a Bitbucket repository, one commit per deploy.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-bitbucket';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_bitbucket_options',
                [
                    'bitbucketRepository' => [ 'string', '' ],
                    'bitbucketUsername' => [ 'string', '' ],
                    'bitbucketAppPassword' => [ 'password', '' ],
                    'bitbucketBranch' => [ 'string', 'main' ],
                    'bitbucketPath' => [ 'string', '' ],
                    'bitbucketCommitMessage' => [ 'string', 'Published by VibeStatic' ],
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
            'bitbucketRepository' => [
                __( 'Repository', 'vibestatic' ),
                __( 'As workspace/name, for example acme/acme-site.', 'vibestatic' ),
            ],
            'bitbucketUsername' => [
                __( 'Username', 'vibestatic' ),
                __( 'The Bitbucket username the app password belongs to, not the email address.', 'vibestatic' ),
            ],
            'bitbucketAppPassword' => [
                __( 'App password', 'vibestatic' ),
                __(
                    'An app password with Repositories: Write. The account password has not worked on the API since 2022. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'bitbucketBranch' => [
                __( 'Branch', 'vibestatic' ),
                __( 'It has to exist already: the add-on commits to it, it does not create it.', 'vibestatic' ),
            ],
            'bitbucketPath' => [
                __( 'Path within the repository', 'vibestatic' ),
                __( 'Optional. Leave blank to commit at the repository root.', 'vibestatic' ),
            ],
            'bitbucketCommitMessage' => [
                __( 'Commit message', 'vibestatic' ),
                __( 'A deploy split across several commits gets a counter appended.', 'vibestatic' ),
            ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic bitbucket', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
