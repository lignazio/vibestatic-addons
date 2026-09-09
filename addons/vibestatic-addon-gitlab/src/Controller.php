<?php
/**
 * GitLab deployment.
 *
 * @package WP2StaticGitLab
 */

namespace WP2StaticGitLab;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-gitlab';
    }

    public function name() : string {
        return __( 'GitLab', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Commits the generated site to a GitLab project, one commit per deploy.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-gitlab';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_gitlab_options',
                [
                    'gitlabProject' => [ 'string', '' ],
                    'gitlabToken' => [ 'password', '' ],
                    'gitlabBranch' => [ 'string', 'main' ],
                    'gitlabPath' => [ 'string', '' ],
                    'gitlabCommitMessage' => [ 'string', 'Published by VibeStatic' ],
                    'gitlabApiUrl' => [ 'string', '' ],
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
            'gitlabProject' => [
                __( 'Project', 'vibestatic' ),
                __( 'Its full path, such as group/subgroup/site, or its numeric ID.', 'vibestatic' ),
            ],
            'gitlabToken' => [
                __( 'Access token', 'vibestatic' ),
                __(
                    'A project access token with the api scope and the Maintainer role. Stored encrypted; leave blank to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'gitlabBranch' => [
                __( 'Branch', 'vibestatic' ),
                __( 'It has to exist already: the add-on commits to it, it does not create it.', 'vibestatic' ),
            ],
            'gitlabPath' => [
                __( 'Path within the project', 'vibestatic' ),
                __( 'Optional, such as public. Leave blank to commit at the project root.', 'vibestatic' ),
            ],
            'gitlabCommitMessage' => [
                __( 'Commit message', 'vibestatic' ),
                __( 'A deploy split across several commits gets a counter appended.', 'vibestatic' ),
            ],
            'gitlabApiUrl' => [
                __( 'API URL', 'vibestatic' ),
                __(
                    'For a self-managed GitLab, such as https://git.example.com/api/v4/. Leave blank for gitlab.com.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic gitlab', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }
}
