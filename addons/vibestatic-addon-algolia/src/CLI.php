<?php
/**
 * `wp vibestatic algolia options|indices|objects`
 *
 * **Static**, which is the whole of one bug. Upstream registered
 * `[ 'WP2StaticAlgolia\CLI', 'algolia' ]` while declaring the method non-static:
 * on PHP 8 that is `Error: Non-static method cannot be called statically`, so
 * the command failed the first time anybody used it. The same mistake is in the
 * BunnyCDN add-on's CLI.
 *
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia;

use WP_CLI;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     */
    public static function command( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        switch ( $args[0] ?? '' ) {
            case 'options':
                OptionsCommand::run( Controller::instance()->options(), $args );
                break;

            case 'indices':
                self::render( self::client()->indices() );
                break;

            case 'objects':
                self::render(
                    self::client()->objects( $args[1] ?? Client::DEFAULT_INDEX )
                );
                break;

            default:
                WP_CLI::error( 'Missing required argument: <options|indices|objects>' );
        }
    }

    private static function client() : Client {
        $client = Client::fromPluginOptions();

        if ( null === $client ) {
            WP_CLI::error(
                'No Algolia credentials. They come from the WP Search with Algolia plugin, which does not look installed.'
            );
        }

        /** @var Client $client */
        return $client;
    }

    /**
     * @param mixed[] $value Anything JSON-encodable.
     */
    private static function render( array $value ) : void {
        WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
}
