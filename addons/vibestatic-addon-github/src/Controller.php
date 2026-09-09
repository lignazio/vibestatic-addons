<?php
/**
 * GitHub deployment.
 *
 * @package WP2StaticGitHub
 */

namespace WP2StaticGitHub;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-github';
    }

    public function name() : string {
        return __( 'GitHub', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Commits the generated site to a GitHub repository, one commit per deploy.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-github';
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
                'wp2static_addon_github_options',
                [
                    'githubRepository' => [ 'string', '' ],
                    'githubToken' => [ 'password', '' ],
                    'githubBranch' => [ 'string', 'main' ],
                    'githubPath' => [ 'string', '' ],
                    'githubCommitMessage' => [ 'string', 'Published by VibeStatic' ],
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
            'githubRepository' => [
                __( 'Repository', 'vibestatic' ),
                __( 'As owner/name, for example acme/acme.github.io.', 'vibestatic' ),
            ],
            'githubToken' => [
                __( 'Access token', 'vibestatic' ),
                __(
                    'A fine-grained personal access token with read and write on this repository\'s contents. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'githubBranch' => [
                __( 'Branch', 'vibestatic' ),
                __( 'It has to exist already: the add-on commits to it, it does not create it.', 'vibestatic' ),
            ],
            'githubPath' => [
                __( 'Path within the repository', 'vibestatic' ),
                __( 'Optional, such as docs. Leave blank to commit at the repository root.', 'vibestatic' ),
            ],
            'githubCommitMessage' => [
                __( 'Commit message', 'vibestatic' ),
                __( 'A deploy split across several commits gets a counter appended.', 'vibestatic' ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'A classic token works too, but a fine-grained one scoped to this repository is the smaller thing to lose: the old add-on asked for a token with access to everything.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic github', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
