<?php
/**
 * The guard that keeps an add-on off a core it cannot run on.
 *
 * An add-on's Controller `extends \WP2Static\Addon\Controller`. On a core that
 * does not have that class, merely autoloading the Controller is a fatal error
 * — during `plugins_loaded`, which means a white screen with no way back except
 * editing the database. The guard therefore sits in each add-on's main file, in
 * plain PHP, and nothing there may touch the add-on's own classes.
 *
 * That makes it the one piece of these plugins that cannot be shared, and so
 * the one piece that has to be identical ten times over. Both halves are tested
 * here: that it behaves, and that all ten say the same thing.
 *
 * @package VibeStaticAddons
 */

namespace VibeStatic\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A process of its own: this defines VIBESTATIC_VERSION, and a constant cannot
 * be taken back.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CoreGuardTest extends TestCase {

    /**
     * Load one add-on's main file with a given core in place.
     *
     * The plugin file is read and evaluated rather than required, so the test
     * can put it in a namespace of its own and load it more than once in a run
     * without redeclaring anything.
     *
     * @param string|null $core_version What VIBESTATIC_VERSION says, or null for no core.
     * @return array{booted: bool, notices: int} What the add-on did.
     */
    private function bootWith( ?string $core_version, string $namespace ) : array {
        $source = (string) file_get_contents(
            __DIR__ . '/../../addons/vibestatic-addon-boilerplate/vibestatic-addon-boilerplate.php'
        );

        // Everything from the constants down; the header and the autoloader
        // require are not what is under test.
        $source = substr( $source, (int) strpos( $source, '/**' . "\n" . ' * The tag that carries' ) );

        $hooks = [];
        $booted = false;

        // The WordPress surface the guard uses, and nothing else.
        $prelude = "namespace $namespace;\n"
            . 'function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["guard_hooks"][ $hook ][] = $callback; }' . "\n"
            . 'function current_user_can( $cap ) { return true; }' . "\n"
            . 'function esc_html( $s ) { return $s; }' . "\n"
            . 'function esc_html__( $s, $d = "" ) { return $s; }' . "\n"
            . 'function __( $s, $d = "" ) { return $s; }' . "\n"
            . 'function register_activation_hook( $file, $callback ) { $GLOBALS["guard_activation"] = $callback; }' . "\n"
            . 'class Controller { public static function boot() { $GLOBALS["guard_booted"] = true; } '
            . 'public static function activate( $n = null ) { $GLOBALS["guard_activated"] = true; } }' . "\n"
            // Since 1.1.0 the add-on registers with its *own* Updater — the one
            // generated into its src/ — instead of the core's. In this harness
            // the add-on's classes are not autoloaded at all, so it gets a stub
            // like Controller has, and for the same reason: what is under test
            // is the guard, not what boots behind it.
            . 'class Updater { public static function register( $f, $p, $n ) { $GLOBALS["guard_registered"] = $p; } }' . "\n";

        $GLOBALS['guard_hooks'] = [];
        $GLOBALS['guard_booted'] = false;

        if ( null !== $core_version && ! defined( 'VIBESTATIC_VERSION' ) ) {
            define( 'VIBESTATIC_VERSION', $core_version );
        }

        eval( $prelude . str_replace( '<?php', '', $source ) );

        // What the plugin registered on plugins_loaded, run.
        foreach ( $GLOBALS['guard_hooks']['plugins_loaded'] ?? [] as $callback ) {
            $callback();
        }

        return [
            'booted' => (bool) $GLOBALS['guard_booted'],
            'notices' => count( $GLOBALS['guard_hooks']['admin_notices'] ?? [] ),
        ];
    }

    /**
     * No core at all: no boot, and a notice saying so.
     */
    public function testWithoutTheCoreItDoesNotBoot() : void {
        $result = $this->bootWith( null, 'GuardNoCore' );

        $this->assertFalse( $result['booted'] );
        $this->assertSame( 1, $result['notices'] );
    }

    /**
     * A core that is there but too old. This is the case the guard was added
     * for: before 9.0 the base class does not exist, and the add-on would die
     * autoloading its own Controller.
     */
    public function testWithAnOldCoreItDoesNotBoot() : void {
        $result = $this->bootWith( '8.1.1', 'GuardOldCore' );

        $this->assertFalse( $result['booted'] );
        $this->assertSame( 1, $result['notices'] );
    }

    /**
     * The series is `9.0`, not `9.0.0`, so a release candidate of the core it
     * is built against satisfies it — version_compare() ranks a prerelease
     * below the release it leads to.
     */
    public function testAReleaseCandidateOfTheRequiredCoreIsEnough() : void {
        $result = $this->bootWith( '9.0.0-rc1', 'GuardRcCore' );

        $this->assertTrue( $result['booted'] );
        $this->assertSame( 0, $result['notices'] );

        // And it asked to be kept up to date, with its own tag prefix.
        $this->assertSame( 'boilerplate', $GLOBALS['guard_registered'] ?? null );
    }

    /**
     * All ten say the same thing.
     *
     * The guard cannot be shared, so the only thing standing between one
     * corrected copy and nine stale ones is this.
     */
    public function testTheGuardIsIdenticalInAllTen() : void {
        $seen = [];

        foreach ( glob( __DIR__ . '/../../addons/vibestatic-addon-*' ) as $dir ) {
            $name = basename( (string) $dir );
            $source = (string) file_get_contents( "$dir/$name.php" );

            $start = strpos( $source, 'function coreIsUsable()' );
            $end = strpos( $source, 'add_action(' );

            $this->assertIsInt( $start, "$name has no coreIsUsable()" );
            $this->assertIsInt( $end, "$name never calls add_action()" );

            $seen[ $name ] = substr( $source, (int) $start, (int) $end - (int) $start );
        }

        $this->assertCount( 10, $seen );
        $this->assertCount(
            1,
            array_unique( $seen ),
            'The guard has drifted between add-ons: ' . implode( ', ', array_keys( $seen ) )
        );
    }

    /**
     * Activation is guarded too.
     *
     * `register_activation_hook( __FILE__, [ Controller::class, 'activate' ] )`
     * looks harmless — a string and a method name, nothing autoloaded. It is
     * not: when the hook fires, the Controller is autoloaded, and it extends a
     * core class. Activating the add-on on a site without VibeStatic would be a
     * fatal error during activation rather than a notice.
     */
    public function testActivationIsGuardedToo() : void {
        foreach ( glob( __DIR__ . '/../../addons/vibestatic-addon-*' ) as $dir ) {
            $name = basename( (string) $dir );
            $source = (string) file_get_contents( "$dir/$name.php" );

            $hook = substr( $source, (int) strpos( $source, 'register_activation_hook' ) );

            $this->assertStringContainsString(
                'coreIsUsable()',
                $hook,
                "$name activates without checking the core first"
            );
        }
    }
}
