<?php
/**
 * `wp vibestatic boilerplate options get|set|list`
 *
 * A thin, **static** entry point. Upstream registered
 * `[ 'WP2StaticBoilerplate\CLI', 'boilerplate' ]` while declaring the method
 * non-static: on PHP 8 calling a non-static method statically is an Error, so
 * every one of these add-ons' commands died the first time anybody ran it.
 *
 * @package WP2StaticBoilerplate
 */

namespace WP2StaticBoilerplate;

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
