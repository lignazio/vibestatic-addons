<?php
/**
 * The core's surface, as far as the add-on touches it.
 *
 * The core is a runtime dependency, not a Composer one — it is installed
 * alongside, not required — so it is not in `vendor/` and cannot be autoloaded
 * here. These are the classes and methods the add-on actually calls; anything
 * it starts calling that is not below will fail loudly rather than silently.
 *
 * @package WP2StaticAdvancedCrawling
 */

namespace WP2Static {

    // `false` so this does not trigger an autoloader: if the real core happens
    // to be reachable, these stubs are still the ones the tests run against.
    if ( ! class_exists( __NAMESPACE__ . '\WsLog', false ) ) {
        class WsLog {

            /**
             * @var string[] Everything logged during a test.
             */
            public static $lines = [];

            /**
             * @param string $text The line to log.
             */
            public static function l( string $text ) : void {
                self::$lines[] = $text;
            }
        }

        class CoreOptions {

            /**
             * Reversible and obvious, so a test can assert that a value went in
             * encrypted without asserting on the real cipher.
             *
             * @param string $action `encrypt` or `decrypt`.
             * @param string $value  What to transform.
             */
            public static function encrypt_decrypt( string $action, string $value ) : string {
                if ( 'encrypt' === $action ) {
                    return 'ENCRYPTED:' . $value;
                }

                return 0 === strpos( $value, 'ENCRYPTED:' ) ? substr( $value, 10 ) : $value;
            }

            /**
             * @param string $name Option name.
             * @return string
             */
            public static function getValue( string $name ) {
                return $GLOBALS['core_options'][ $name ] ?? '';
            }
        }

        class Controller {

            /**
             * @var string[] Nonce actions authorize() was called with.
             */
            public static $authorized = [];

            /**
             * @param string $nonce_action The action to check.
             */
            public static function authorize( string $nonce_action ) : void {
                self::$authorized[] = $nonce_action;
            }
        }

        class SiteInfo {

            /**
             * @param string $name Which URL to return.
             */
            public static function getURL( string $name ) : string {
                return $GLOBALS['site_url'] ?? 'https://wp.example.com/';
            }
        }

        class DeployPlan {

            /**
             * @var string[]
             */
            private $to_deploy;

            /**
             * @var string[]
             */
            private $to_delete;

            /**
             * @var int
             */
            private $unchanged;

            /**
             * @param string[] $to_deploy Paths to send.
             * @param string[] $to_delete Paths that have gone.
             * @param int      $unchanged How many were left alone.
             */
            public function __construct( array $to_deploy, array $to_delete, int $unchanged = 0 ) {
                $this->to_deploy = $to_deploy;
                $this->to_delete = $to_delete;
                $this->unchanged = $unchanged;
            }

            /**
             * @return string[]
             */
            public function toDeploy() : array {
                return $this->to_deploy;
            }

            /**
             * @return string[]
             */
            public function toDelete() : array {
                return $this->to_delete;
            }

            public function unchanged() : int {
                return $this->unchanged;
            }

            public function isEmpty() : bool {
                return ! $this->to_deploy && ! $this->to_delete;
            }

            public function summary() : string {
                return sprintf(
                    '%d to deploy, %d to delete, %d unchanged',
                    count( $this->to_deploy ),
                    count( $this->to_delete ),
                    $this->unchanged
                );
            }
        }

        class DeployCache {

            /**
             * @var list<array{0: string, 1: string}> Paths recorded as sent.
             */
            public static $added = [];

            /**
             * @var list<array{0: string, 1: string}> Paths dropped from the cache.
             */
            public static $removed = [];

            /**
             * @var DeployPlan|null What plan() should answer.
             */
            public static $plan = null;

            /**
             * @param string        $namespace The deployer's namespace.
             * @param string[]|null $paths     Unused here.
             */
            public static function plan( string $namespace = 'default', ?array $paths = null ) : DeployPlan {
                return self::$plan ?? new DeployPlan( [], [] );
            }

            /**
             * @param string      $path      The path that arrived.
             * @param string      $namespace The deployer's namespace.
             * @param string|null $hash      Optional content hash.
             */
            public static function addFile( string $path, string $namespace = 'default', ?string $hash = null ) : void {
                self::$added[] = [ $path, $namespace ];
            }

            /**
             * @param string[] $paths     Paths to forget.
             * @param string   $namespace The deployer's namespace.
             */
            public static function rmPaths( array $paths, string $namespace = 'default' ) : void {
                foreach ( $paths as $path ) {
                    self::$removed[] = [ $path, $namespace ];
                }
            }

            public static function reset() : void {
                self::$added = [];
                self::$removed = [];
                self::$plan = null;
            }
        }

        class URLHelper {

            /**
             * @param string $url The URL to make protocol-relative.
             */
            public static function getProtocolRelativeURL( string $url ) : string {
                return (string) preg_replace( '/^https?:/', '', $url );
            }
        }

        abstract class PlanDrivenDeployer {

            const FAILURES_TO_LOG = 10;

            abstract protected function deployCacheNamespace() : string;

            abstract protected function label() : string;

            /**
             * @param string $local       Absolute path of the file to send.
             * @param string $destination Where it goes, root included.
             */
            abstract protected function put( string $local, string $destination ) : bool;

            /**
             * @param string $destination Where it is, root included.
             */
            abstract protected function delete( string $destination ) : bool;

            /**
             * @param string $destination Where it is, root included.
             */
            abstract protected function removeDirectory( string $destination ) : bool;

            protected function root() : string {
                return '';
            }

            protected function connect() : bool {
                return true;
            }

            protected function disconnect() : void {
            }

            /**
             * @param string $processed_site_path The processed site's directory.
             */
            final public function deploy( string $processed_site_path ) : void {
                if ( ! $this->connect() ) {
                    return;
                }

                $namespace = $this->deployCacheNamespace();
                $plan = DeployCache::plan( $namespace );

                foreach ( $plan->toDeploy() as $path ) {
                    if ( $this->put( $processed_site_path . $path, $this->root() . $path ) ) {
                        DeployCache::addFile( $path, $namespace );
                    }
                }

                foreach ( $plan->toDelete() as $path ) {
                    $this->delete( $this->root() . $path );
                }

                DeployCache::rmPaths( $plan->toDelete(), $namespace );

                $this->disconnect();
            }
        }
    }
}

namespace WP2Static\Vendor\GuzzleHttp {

    if ( ! class_exists( __NAMESPACE__ . '\Client', false ) ) {
        /**
         * Records what would have been sent and answers whatever the test set.
         */
        class Client {

            /**
             * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
             */
            public $calls = [];

            /**
             * @var Response[] Queued answers; the last one repeats.
             */
            public $responses = [];

            /**
             * @param array<string, mixed> $config Guzzle config. Unused.
             */
            public function __construct( array $config = [] ) {
            }

            /**
             * @param string               $method  HTTP verb.
             * @param string               $uri     Where to.
             * @param array<string, mixed> $options Guzzle options.
             */
            public function request( string $method, string $uri, array $options = [] ) : Response {
                $this->calls[] = [ $method, $uri, $options ];

                if ( count( $this->responses ) > 1 ) {
                    return array_shift( $this->responses );
                }

                return $this->responses[0] ?? new Response();
            }
        }

        class Response {

            /**
             * @var int
             */
            private $status;

            /**
             * @var string
             */
            private $body;

            /**
             * @var array<string, string>
             */
            private $headers;

            /**
             * @param int                   $status  HTTP status.
             * @param string                $body    Response body.
             * @param array<string, string> $headers Response headers.
             */
            public function __construct( int $status = 200, string $body = '{}', array $headers = [] ) {
                $this->status = $status;
                $this->body = $body;
                $this->headers = $headers;
            }

            public function getStatusCode() : int {
                return $this->status;
            }

            public function getBody() : string {
                return $this->body;
            }

            /**
             * @param string $name Header name.
             */
            public function getHeaderLine( string $name ) : string {
                return $this->headers[ $name ] ?? '';
            }
        }
    }
}

namespace WP2Static\Vendor\GuzzleHttp\Exception {

    if ( ! interface_exists( __NAMESPACE__ . '\GuzzleException', false ) ) {
        interface GuzzleException {
        }

        class TransferException extends \RuntimeException implements GuzzleException {
        }
    }
}
