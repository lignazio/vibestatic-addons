<?php
/**
 * The core's prefixed Guzzle, as far as the add-ons use it.
 *
 * **Not a stub for running: a declaration for analysing.** At run time these
 * classes come from the core's `vendor-prefixed/`, which Strauss generates at
 * install time and which is therefore not in the core package an add-on pulls
 * in as a dev dependency. Without this file PHPStan cannot resolve
 * `WP2Static\Vendor\GuzzleHttp\Client`, so `$client->request(...)` is `mixed`
 * and every `->getStatusCode()` after it is unchecked.
 *
 * That is not a small gap for these add-ons. Reading a response correctly is
 * most of what they do, and reading it wrongly is what the originals did:
 * BunnyCDN decoded the JSON and took any truthy value as success, so a 401
 * answering `{"Message":"Unauthorized"}` counted as an upload; Cloudflare read
 * `success` from the second response to decide whether the first had worked.
 * Analysis that stops at the client cannot see any of that.
 *
 * The signatures are Guzzle 8's and PSR-7's. Only what the add-ons call is
 * here — the point is the return types, not a copy of the library.
 *
 * @package VibeStaticAddons
 */

namespace WP2Static\Vendor\Psr\Http\Message {

    interface StreamInterface {

        public function __toString() : string;

        public function getContents() : string;
    }

    interface ResponseInterface {

        public function getStatusCode() : int;

        public function getBody() : StreamInterface;

        /**
         * @param string $name Header name.
         */
        public function getHeaderLine( string $name ) : string;
    }
}

namespace WP2Static\Vendor\GuzzleHttp {

    use WP2Static\Vendor\Psr\Http\Message\ResponseInterface;

    class Client {

        /**
         * @param array<string, mixed> $config Guzzle's own options.
         */
        public function __construct( array $config = [] ) {
        }

        /**
         * @param string               $method  HTTP verb.
         * @param string               $uri     Absolute, or relative to base_uri.
         * @param array<string, mixed> $options Guzzle's per-request options.
         */
        public function request( string $method, string $uri = '', array $options = [] ) : ResponseInterface {
        }
    }
}

namespace WP2Static\Vendor\GuzzleHttp\Exception {

    interface GuzzleException extends \Throwable {
    }

    class TransferException extends \RuntimeException implements GuzzleException {
    }

    class RequestException extends TransferException {
    }

    class ConnectException extends TransferException {
    }
}
