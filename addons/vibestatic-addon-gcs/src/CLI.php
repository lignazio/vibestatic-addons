<?php
/**
 * `wp vibestatic gcs options get|set|list`
 *
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS;

use WP2Static\Addon\OptionsCommand;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     */
    public static function command( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        OptionsCommand::run( Controller::instance()->options(), $args );
    }
}
