<?php
/**
 * Writes each add-on's `src/Updater.php` from `tools/updater-template.php`.
 *
 *     php tools/sync_updater.php          write the ten copies
 *     php tools/sync_updater.php --check  fail if any of them has drifted
 *
 * The template is the only place the class is written. Ten copies exist because
 * ten separate plugins each need one in their own zip, and there is nowhere
 * shared that ships with all ten — the core used to be that place, and cannot
 * be any more: a core installed from wordpress.org does not carry an updater
 * for other people's plugins, because guideline 8 forbids it.
 *
 * `--check` is what `verify.php` runs, and what makes the duplication safe. The
 * ancestral add-ons duplicated their boilerplate by hand and it diverged, one
 * defect becoming ten; generated copies with an equality check cannot.
 *
 * @package VibeStaticAddons
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );
$template_path = "$root/tools/updater-template.php";

if ( ! is_readable( $template_path ) ) {
    fwrite( STDERR, "Manca $template_path\n" );
    exit( 1 );
}

$template = (string) file_get_contents( $template_path );

/*
 * The placeholder is itself a legal namespace, so the template is valid PHP and
 * the linter, PHPStan and phpcs read it like any other file instead of having
 * to be taught to skip it. It only has to be a name nothing else uses.
 */
const PLACEHOLDER = 'VIBESTATIC_ADDON_NAMESPACE';

if ( false === strpos( $template, PLACEHOLDER ) ) {
    fwrite( STDERR, 'Il template non contiene ' . PLACEHOLDER . "\n" );
    exit( 1 );
}

$check = in_array( '--check', array_slice( $argv, 1 ), true );
$directories = glob( "$root/addons/vibestatic-addon-*", GLOB_ONLYDIR );

if ( ! $directories ) {
    fwrite( STDERR, "Nessun add-on trovato sotto addons/\n" );
    exit( 1 );
}

$drifted = [];
$written = 0;

foreach ( $directories as $dir ) {
    $slug = basename( $dir );
    $main = "$dir/$slug.php";

    if ( ! is_readable( $main ) ) {
        fwrite( STDERR, "$slug: manca il file principale\n" );
        exit( 1 );
    }

    /*
     * The namespace is read from the add-on's own file rather than derived from
     * its slug: `vibestatic-addon-gcs` is `WP2StaticGCS` and
     * `vibestatic-addon-cloudflare-workers` is `WP2StaticCloudflareWorkers`,
     * and no rule turns one into the other. The file already knows.
     */
    if ( ! preg_match( '/^namespace\s+([^;]+);/m', (string) file_get_contents( $main ), $m ) ) {
        fwrite( STDERR, "$slug: namespace non dichiarato in $slug.php\n" );
        exit( 1 );
    }

    $namespace = trim( $m[1] );
    $expected = str_replace( PLACEHOLDER, $namespace, $template );
    $target = "$dir/src/Updater.php";

    if ( $check ) {
        $actual = is_readable( $target ) ? (string) file_get_contents( $target ) : '';

        if ( $actual !== $expected ) {
            $drifted[] = $slug . ( '' === $actual ? ' (assente)' : '' );
        }

        continue;
    }

    file_put_contents( $target, $expected );
    ++$written;
    echo "$slug/src/Updater.php ($namespace)\n";
}

if ( $check ) {
    if ( $drifted ) {
        fwrite(
            STDERR,
            "Updater fuori sincrono con tools/updater-template.php:\n  - "
            . implode( "\n  - ", $drifted )
            . "\nLancia: php tools/sync_updater.php\n"
        );
        exit( 1 );
    }

    echo 'Updater in sincrono in tutti e ' . count( $directories ) . " gli add-on.\n";
    exit( 0 );
}

echo "$written copie scritte.\n";
