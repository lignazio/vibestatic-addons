<?php
/**
 * Just enough of wpdb to see what a query would have been.
 *
 * `prepare()` substitutes the placeholders the way the real one does, so a test
 * can assert both on the shape of the SQL and on whether a value reached it
 * escaped, encrypted, or not at all.
 *
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia\Tests;

class FakeWpdb {

    /**
     * @var string
     */
    public $prefix = 'wp_';

    /**
     * @var string[] Every query run through this instance.
     */
    public $queries = [];

    /**
     * @var array<string, string> What get_var() should answer, keyed by name.
     */
    public $values = [];

    public function get_charset_collate() : string {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    /**
     * @param string $sql     The query, with placeholders.
     * @param mixed  ...$args What to put in them.
     */
    public function prepare( string $sql, ...$args ) : string {
        foreach ( $args as $arg ) {
            $replacement = is_int( $arg ) || is_float( $arg )
                ? (string) $arg
                : "'" . addslashes( (string) $arg ) . "'";

            $sql = (string) preg_replace( '/%[isdf]/', $replacement, $sql, 1 );
        }

        return $sql;
    }

    /**
     * @param string $sql The query.
     */
    public function query( string $sql ) : int {
        $this->queries[] = $sql;

        return 1;
    }

    /**
     * @param string $sql The query.
     * @return mixed[]
     */
    public function get_results( string $sql ) : array {
        $this->queries[] = $sql;

        return [];
    }

    /**
     * @param string $sql The query.
     * @return string|null
     */
    public function get_var( string $sql ) {
        $this->queries[] = $sql;

        foreach ( $this->values as $name => $value ) {
            if ( false !== strpos( $sql, "'$name'" ) ) {
                return $value;
            }
        }

        return null;
    }
}
