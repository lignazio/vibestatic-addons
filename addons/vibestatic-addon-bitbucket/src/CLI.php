<?php
/**
 * `wp vibestatic bitbucket options get|set|list`
 *
 * **Static**, which is one of the bugs this add-on inherited. Upstream
 * registered its command as a static callable while declaring the method
 * non-static: on PHP 8 that is an Error, so the command failed the first time
 * anybody ran it.
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

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
