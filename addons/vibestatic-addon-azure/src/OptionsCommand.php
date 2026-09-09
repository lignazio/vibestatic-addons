<?php
/**
 * `wp vibestatic <add-on> options get|set|list`
 *
 * **This file is identical in every VibeStatic add-on**, its namespace line
 * apart. Upstream each add-on wrote its own copy, and the copies disagreed:
 * some decrypted secrets on `get` and some did not, most printed them in full
 * in `list` — which is the command whose output gets pasted into a bug report —
 * and every one of them registered a non-static method as a static callable, so
 * the command was a fatal error the moment anybody ran it on PHP 8.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure;

use WP_CLI;

class OptionsCommand {

    /**
     * @param Options  $options The add-on's options.
     * @param string[] $args    Positional CLI arguments, `options` included.
     */
    public static function run( Options $options, array $args ) : void {
        if ( 'options' !== ( $args[0] ?? '' ) ) {
            WP_CLI::error( 'Missing required argument: <options>' );
        }

        switch ( $args[1] ?? '' ) {
            case 'get':
                self::get( $options, $args[2] ?? '' );
                break;

            case 'set':
                self::set( $options, $args[2] ?? '', $args[3] ?? '' );
                break;

            case 'list':
                self::list( $options );
                break;

            default:
                WP_CLI::error( 'Missing required argument: <get|set|list>' );
        }
    }

    /**
     * @param Options $options The add-on's options.
     * @param string  $name    Which one to print.
     */
    private static function get( Options $options, string $name ) : void {
        self::assertKnown( $options, $name );

        WP_CLI::line( $options->plain( $name ) );
    }

    /**
     * @param Options $options The add-on's options.
     * @param string  $name    Which one to write.
     * @param string  $value   What to write.
     */
    private static function set( Options $options, string $name, string $value ) : void {
        self::assertKnown( $options, $name );

        $options->save( $name, $value );

        WP_CLI::success( "Saved $name." );
    }

    /**
     * Every option and its value, **secrets masked**.
     *
     * Upstream printed the decrypted token here, which is the same defect the
     * core had on its Diagnostics page: the one command a user is asked to run
     * when reporting a problem was the one that put their credentials in the
     * report. `get` still prints a secret in full, because that is a
     * deliberate, single-value request.
     *
     * @param Options $options The add-on's options.
     */
    private static function list( Options $options ) : void {
        $rows = [];

        foreach ( $options->names() as $name ) {
            if ( ! $options->isSecret( $name ) ) {
                $rows[] = [
					'name' => $name,
					'value' => $options->get( $name ),
				];

                continue;
            }

            $rows[] = [
                'name' => $name,
                'value' => '' === $options->get( $name ) ? 'not set' : 'set (hidden)',
            ];
        }

        WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'value' ] );
    }

    /**
     * Refuse a name the add-on does not declare.
     *
     * Upstream `options set` wrote whatever name it was given. A typo therefore
     * looked like it had worked — it created a row nothing ever read — and the
     * deploy went on using the old value with no indication why.
     *
     * @param Options $options The add-on's options.
     * @param string  $name    The name to check.
     */
    private static function assertKnown( Options $options, string $name ) : void {
        if ( '' === $name ) {
            WP_CLI::error( 'Missing required argument: <option-name>' );
        }

        if ( in_array( $name, $options->names(), true ) ) {
            return;
        }

        WP_CLI::error(
            "Unknown option: $name. Known options: " . implode( ', ', $options->names() )
        );
    }
}
