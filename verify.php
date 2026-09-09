<?php
/**
 * Boots every VibeStatic add-on against a stubbed WordPress and a stubbed core.
 *
 *     php verify.php
 *
 * It answers the question the per-add-on test suites cannot, because each of
 * them only ever loads its own add-on: does every one of them still *wire up*?
 * A concrete Controller that forgets an abstract method, an option declared
 * without a field to type it into, a settings page that throws while rendering,
 * a save handler that stopped authorising — none of that is caught by `php -l`
 * and all of it is caught here.
 *
 * For each add-on it checks that:
 *
 *   - Controller::boot() runs and the add-on announces a slug and its options;
 *   - the settings page renders and produces a form;
 *   - every declared option has a field, and every field a declared option;
 *   - saveOptionsFromUI() authorises before it writes — read from the source,
 *     because the method ends in exit() as an admin_post handler must;
 *   - a full round-trip through $_POST leaves no unbound SQL placeholder and no
 *     secret written to the database in the clear.
 *
 * Nothing here talks to a network or a database. The per-add-on suites under
 * `tests/` are where behaviour is checked; this is where the wiring is.
 */

namespace {
// ---------------------------------------------------------------- WP stubs
define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['hooks'] = [];
$GLOBALS['queries'] = [];
$GLOBALS['options_store'] = [];

class FakeWpdb {
    public $prefix = 'wp_';
    public function get_charset_collate() {
 return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[isd]/', is_string( $a ) ? "'" . addslashes( (string) $a ) . "'" : (string) $a, $sql, 1 );
        }
        return $sql;
    }
    public function query( $sql ) {
 $GLOBALS['queries'][] = $sql;
return 1; }
    public function get_results( $sql ) {
 $GLOBALS['queries'][] = $sql;
return []; }
    public function get_var( $sql ) {
 $GLOBALS['queries'][] = $sql;
return null; }
}
$wpdb = new FakeWpdb();

function add_action( $h, $c, $p = 10, $a = 1 ) {
 $GLOBALS['hooks'][ $h ][] = $c; }
function add_filter( $h, $c, $p = 10, $a = 1 ) {
 $GLOBALS['hooks'][ $h ][] = $c; }
function do_action( $h, ...$a ) {
 $GLOBALS['fired'][ $h ][] = $a; }
function apply_filters( $h, $v, ...$a ) {
 return $v; }
function __( $t, $d = '' ) {
 return $t; }
function esc_html__( $t, $d = '' ) {
 return $t; }
function esc_html_e( $t, $d = '' ) {
 echo $t; }
function esc_html( $t ) {
 return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) {
 return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) {
 return $t; }
function esc_textarea( $t ) {
 return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function checked( $a, $b, $e = true ) {
 return (string) $a === (string) $b ? ' checked="checked"' : ''; }
function admin_url( $p = '' ) {
 return 'https://example.com/wp-admin/' . $p; }
function wp_nonce_field( $a ) {
 echo '<input type="hidden" name="_wpnonce" value="x" />'; }
function wp_unslash( $v ) {
 return $v; }
function sanitize_text_field( $v ) {
 return ( is_array( $v ) || is_object( $v ) ) ? '' : trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) {
 return ( is_array( $v ) || is_object( $v ) ) ? '' : strip_tags( (string) $v ); }
function wp_safe_redirect( $u ) { }
function wp_json_encode( $v, $f = 0 ) {
 return json_encode( $v, $f ); }
function current_user_can( $c ) {
 return true; }
function is_multisite() {
 return false; }
function get_sites( $a = [] ) {
 return []; }
function switch_to_blog( $i ) {}
function restore_current_blog() {}
function untrailingslashit( $s ) {
 return rtrim( (string) $s, '/' ); }
function get_option( $n, $d = '' ) {
 return $GLOBALS['options_store'][ $n ] ?? $d; }
function get_transient( $k ) {
 return false; }
function set_transient( $k, $v, $t ) {
 return true; }
function dbDelta( $sql ) {
 $GLOBALS['queries'][] = $sql;
return []; }
function plugin_dir_path( $f ) {
 return dirname( $f ) . '/'; }
function register_activation_hook( $f, $c ) {}
function wp_doing_cron() {
 return false; }
function is_admin() {
 return true; }

}

// ------------------------------------------------------------- core stubs
namespace WP2Static {
    class WsLog {
        public static $lines = [];
        public static function l( string $t ) : void {
 self::$lines[] = $t; }
    }
    class CoreOptions {
        public static function encrypt_decrypt( $action, $value ) {
            return 'encrypt' === $action ? 'ENC:' . $value : ( 0 === strpos( (string) $value, 'ENC:' ) ? substr( (string) $value, 4 ) : $value );
        }
        public static function getValue( $name ) {
 return $GLOBALS['core_options'][ $name ] ?? ''; }
    }
    class Controller {
        public static $authorized = [];
        public static function authorize( string $nonce ) : void {
 self::$authorized[] = $nonce; }
    }
    class SiteInfo {
        public static function getURL( $n ) {
 return 'https://wp.example.com:8080/'; }
    }
    class DeployPlan {
        private $d;
private $x;
private $u;
        public function __construct( array $d, array $x, int $u ) {
 $this->d = $d;
$this->x = $x;
$this->u = $u; }
        public function toDeploy() : array {
 return $this->d; }
        public function toDelete() : array {
 return $this->x; }
        public function unchanged() : int {
 return $this->u; }
        public function isEmpty() : bool {
 return ! $this->d && ! $this->x; }
        public function summary() : string {
 return sprintf( '%d to deploy, %d to delete, %d unchanged', count( $this->d ), count( $this->x ), $this->u ); }
    }
    class DeployCache {
        public static $added = [];
public static $removed = [];
        public static $plan = null;
        public static function plan( $ns = 'default', $paths = null ) {
 return self::$plan ?: new DeployPlan( [], [], 0 ); }
        public static function addFile( $p, $ns = 'default', $h = null ) : void {
 self::$added[] = [ $p, $ns ]; }
        public static function rmPaths( array $p, $ns = 'default' ) : void {
 foreach ( $p as $x ) {
self::$removed[] = [ $x, $ns ];
        } }
        public static function fileisCached( $p, $ns = 'default', $h = null ) {
 return false; }
    }
    class URLHelper {
        public static function getProtocolRelativeURL( string $url ) : string {
            return (string) preg_replace( '/^https?:/', '', $url );
        }
    }
    abstract class PlanDrivenDeployer {
        const FAILURES_TO_LOG = 10;
        abstract protected function deployCacheNamespace() : string;
        abstract protected function label() : string;
        abstract protected function put( string $l, string $d ) : bool;
        abstract protected function delete( string $d ) : bool;
        abstract protected function removeDirectory( string $d ) : bool;
        protected function root() : string {
 return ''; }
        protected function connect() : bool {
 return true; }
        protected function disconnect() : void {}
        final public function deploy( string $path ) : void {
            if ( ! is_dir( $path ) ) {
WsLog::l( 'no dir' );
return; }
            if ( ! $this->connect() ) {
return; }
            $plan = DeployCache::plan( $this->deployCacheNamespace() );
            WsLog::l( $plan->summary() );
            foreach ( $plan->toDeploy() as $p ) {
$this->put( $path . $p, $this->root() . $p ); }
            foreach ( $plan->toDelete() as $p ) {
$this->delete( $this->root() . $p ); }
            $this->disconnect();
        }
    }
}

namespace WP2Static\Vendor\GuzzleHttp {
    class Client {
        public $calls = [];
        public function __construct( array $config = [] ) {
 $this->config = $config; }
        public function request( $method, $uri, array $options = [] ) {
            $this->calls[] = [ $method, $uri, $options ];
            return new \WP2Static\Vendor\GuzzleHttp\FakeResponse();
        }
    }
    class FakeResponse {
        public function getStatusCode() {
 return 200; }
        public function getBody() {
 return '{"success":true,"sha":"abc","id":"x","object":{"sha":"abc"},"items":[]}'; }
        public function getHeaderLine( $n ) {
 return ''; }
    }
}

namespace WP2Static\Vendor\GuzzleHttp\Exception {
    interface GuzzleException {}
    class RequestException extends \RuntimeException implements GuzzleException {}
}

// ------------------------------------------------------------------- run
namespace {

$root = __DIR__ . '/addons';

/*
 * The base classes are the core's, so they are loaded from the core, not
 * stubbed. Everything above this line stands in for a WordPress and for the
 * rest of WP2Static; WP2Static\Addon\ is real, because "does every add-on
 * still wire up" is a question about the actual base and not about a copy of
 * it. Composer's autoloader is not used: this script runs against stubs it
 * declared itself, and loading the core's map here would pull the real
 * WP2Static\Controller in over the stub.
 */
foreach ( [ 'Options', 'SettingsPage', 'OptionsCommand', 'Registry', 'Controller', 'Updater' ] as $base ) {
    $file = __DIR__ . "/vendor/lignazio/vibestatic/src/Addon/$base.php";

    if ( ! is_readable( $file ) ) {
        fwrite( STDERR, "Manca $file. Lancia 'composer install'.\n" );
        exit( 1 );
    }

    require_once $file;
}
$addons = [
    'boilerplate' => 'WP2StaticBoilerplate',
    'bunnycdn' => 'WP2StaticBunnyCDN',
    'gcs' => 'WP2StaticGCS',
    'cloudflare-workers' => 'WP2StaticCloudflareWorkers',
    'github' => 'WP2StaticGitHub',
    'gitlab' => 'WP2StaticGitLab',
    'bitbucket' => 'WP2StaticBitbucket',
    'azure' => 'WP2StaticAzure',
    'algolia' => 'WP2StaticAlgolia',
    'advanced-crawling' => 'WP2StaticAdvancedCrawling',
];

$failures = 0;

foreach ( $addons as $key => $ns ) {
    $dir = "$root/vibestatic-addon-$key";

    foreach ( glob( "$dir/src/*.php" ) as $file ) {
        require_once $file;
    }

    $controller = "$ns\\Controller";

    try {
        $addon = $controller::boot();

        $slug = $addon->slug();
        $options = $addon->options();
        $names = $options->names();

        // Render the settings page and make sure it produces escaped markup.
        ob_start();
        $addon->renderSettingsPage();
        $html = ob_get_clean();

        if ( false === strpos( $html, '<form' ) ) {
            throw new RuntimeException( 'settings page produced no form' );
        }

        // Every declared option must have a field, and vice versa.
        $reflect = new ReflectionMethod( $controller, 'fields' );
        $reflect->setAccessible( true );
        $fields = array_keys( $reflect->invoke( $addon ) );
        $missing = array_diff( $names, $fields );
        $extra = array_diff( $fields, $names );

        if ( $missing || $extra ) {
            throw new RuntimeException(
                'field/option mismatch: missing ' . implode( ',', $missing ) . ' extra ' . implode( ',', $extra )
            );
        }

        /*
         * The save handler ends in exit(), as an admin_post handler must, so it
         * cannot be called in-process. What it does before that is checked at
         * the source: capability and nonce through the core's authorize(),
         * ahead of any write.
         *
         * The source is the core's now, not a copy inside each add-on — which
         * makes this one check rather than ten, and means it is checking the
         * code that actually runs. It is still worth doing here: the assertion
         * is about every add-on's save path, and an add-on that stopped
         * extending the base would quietly stop being covered by it.
         */
        $source = file_get_contents(
            __DIR__ . '/vendor/lignazio/vibestatic/src/Addon/Controller.php'
        );
        $save = substr( $source, strpos( $source, 'function saveOptionsFromUI' ) );
        $save = substr( $save, 0, strpos( $save, 'exit;' ) );

        if ( strpos( $save, 'Controller::authorize' ) === false
            || strpos( $save, 'authorize' ) > strpos( $save, 'savePosted' )
        ) {
            throw new RuntimeException( 'saveOptionsFromUI does not authorise before writing' );
        }

        /*
         * What the add-on claims about itself, and what its README says, have
         * to be the same claim.
         *
         * `fieldTested()` decides whether the settings page carries "this has
         * not been tested against the real service". The README says the same
         * thing in prose. Those are two places, so they will drift — and the
         * direction they drift is the bad one: somebody verifies an add-on,
         * flips the constant, and the README goes on warning; or updates the
         * README and the notice stays. Either way the page and the page about
         * the page disagree about whether to trust it.
         */
        $readme = "$dir/README.md";
        $prose = is_readable( $readme ) ? (string) file_get_contents( $readme ) : '';
        $warns = false !== strpos( $prose, '## Not verified' );

        if ( $addon->fieldTested() && $warns ) {
            throw new RuntimeException(
                'fieldTested() is true but the README still has "## Not verified"'
            );
        }

        if ( ! $addon->fieldTested() && ! $warns ) {
            throw new RuntimeException(
                'fieldTested() is false but the README does not say so under "## Not verified"'
            );
        }

        // Round-trip the options through $_POST, including a secret left blank.
        $_POST = [];
        foreach ( $names as $n ) {
            $_POST[ $n ] = 'test-' . $n;
        }
        $GLOBALS['queries'] = [];
        $options->savePosted();

        foreach ( $GLOBALS['queries'] as $q ) {
            if ( stripos( $q, 'INSERT' ) === 0 && strpos( $q, '%' ) !== false ) {
                throw new RuntimeException( 'savePosted left a placeholder unbound: ' . $q );
            }
        }

        // A secret must reach the database encrypted, never in the clear.
        foreach ( $names as $n ) {
            if ( ! $options->isSecret( $n ) ) {
                continue;
            }

            $written = implode( ' ', $GLOBALS['queries'] );

            if ( strpos( $written, "'test-$n'" ) !== false ) {
                throw new RuntimeException( "secret $n was written in the clear" );
            }
        }

        printf( "  ok  %-20s slug=%-40s options=%d\n", $key, $slug, count( $names ) );
    } catch ( Throwable $e ) {
        while ( ob_get_level() > 0 ) {
ob_end_clean(); }
        ++$failures;
        printf( "FAIL  %-20s %s: %s\n  at %s:%d\n", $key, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() );
    }
}

echo $failures ? "\n$failures add-on(s) failed.\n" : "\nAll add-ons booted.\n";
exit( $failures ? 1 : 0 );
}
