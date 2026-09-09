<?php
/**
 * The reference add-on.
 *
 * Everything an add-on has to say for itself is in this file, and it is short
 * on purpose: the shared parts live in AddonController, Options, SettingsPage
 * and OptionsCommand, which you copy unchanged. That is the whole point of this
 * plugin — upstream's boilerplate was 352 lines of Controller that ten add-ons
 * copied *and then edited*, and the edits are where the defects came from.
 *
 * To write an add-on: copy this directory, rename the namespace, and change
 * what is below.
 *
 * @package WP2StaticBoilerplate
 */

namespace WP2StaticBoilerplate;

class Controller extends AddonController {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-boilerplate';
    }

    public function name() : string {
        return __( 'Boilerplate', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Reference add-on: reports what a deploy would send, without sending it.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addon-boilerplate';
    }

    /**
     * Declare the options once. Type and default here, label and hint in
     * fields() below — the label is a translated string and belongs where it
     * can be re-translated, not in a database column written on first use.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_boilerplate_options',
                [
                    'aRegularOption' => [ 'string', '' ],
                    'anEncryptedOption' => [ 'password', '' ],
                    'listEveryPath' => [ 'bool', '0' ],
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
            'aRegularOption' => [
                __( 'A regular option', 'vibestatic' ),
                __( 'Stored as it is typed.', 'vibestatic' ),
            ],
            'anEncryptedOption' => [
                __( 'An encrypted option', 'vibestatic' ),
                __(
                    'Stored encrypted with the core key. Leave blank to keep the saved value.',
                    'vibestatic'
                ),
            ],
            'listEveryPath' => [
                __( 'List every path', 'vibestatic' ),
                __(
                    'Write one log line per file instead of a single summary. Noisy on a large site.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'This add-on sends nothing anywhere. Enable it as the deployer to see what a real deploy would upload and remove.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        add_action( 'wp2static_post_deploy_trigger', [ $this, 'afterDeploy' ], 15, 1 );

        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic boilerplate', [ CLI::class, 'command' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        ( new Deployer( $this->options() ) )->deploy( $processed_site_path );
    }

    /**
     * Where cache invalidation, a webhook or a notification would go.
     *
     * @param string $enabled_deployer Slug of the deployer that just ran.
     */
    public function afterDeploy( string $enabled_deployer = '' ) : void {
        if ( $this->slug() !== $enabled_deployer ) {
            return;
        }

        \WP2Static\WsLog::l( 'Boilerplate: post-deploy hook fired.' );
    }
}
