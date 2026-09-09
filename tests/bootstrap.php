<?php
/**
 * Test bootstrap for every add-on in this repository.
 *
 * There is no WordPress here and no database: these are unit tests of the
 * add-ons' own logic. WP_Mock stands in for the WordPress functions, `stubs.php`
 * stands in for the parts of the core an add-on calls, and FakeWpdb records the
 * SQL that would have run.
 *
 * What is deliberately **not** stubbed is `WP2Static\Addon\` — Controller,
 * Options, SettingsPage, OptionsCommand. Those are the core's real classes,
 * required as a dev dependency: they are the base every add-on here extends, so
 * testing against a copy of them would be testing the copy. A change in the core
 * that breaks an add-on fails this suite rather than somebody's site.
 *
 * Order matters. The stubs are loaded before anything can autoload the real
 * core, and they guard themselves with `class_exists( ..., false )` so they win:
 * `WP2Static\WsLog` and friends stay fake while `WP2Static\Addon\Options` is
 * real, which is exactly the split wanted.
 *
 * @package VibeStaticAddons
 */

require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/FakeWpdb.php';

// Every add-on's classes. They are not in a Composer autoload map because each
// add-on ships its own four-line autoloader instead of a vendor/ directory.
foreach ( glob( __DIR__ . '/../addons/*/src/*.php' ) as $class_file ) {
    require_once $class_file;
}
