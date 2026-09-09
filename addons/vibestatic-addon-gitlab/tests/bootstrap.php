<?php
/**
 * Test bootstrap.
 *
 * There is no WordPress here and no database: these are unit tests of the
 * add-on's own logic. WP_Mock stands in for the WordPress functions, a handful
 * of stubs stand in for the parts of the core the add-on calls, and FakeWpdb
 * records the SQL that would have run.
 *
 * @package WP2StaticGitLab
 */

require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();

require_once __DIR__ . '/stubs.php';

foreach ( glob( __DIR__ . '/../src/*.php' ) as $class_file ) {
    require_once $class_file;
}
