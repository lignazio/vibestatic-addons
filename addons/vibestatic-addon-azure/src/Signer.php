<?php
/**
 * Azure Storage Shared Key signatures.
 *
 * **Why there is no SDK.** The add-on this replaces declared no dependency at
 * all and simply called `MicrosoftAzure\Storage\Blob\BlobRestProxy`, hoping
 * something else had installed it; the package it meant,
 * `microsoft/azure-storage-blob`, was retired by Microsoft in 2024 and its
 * replacement is generated code with a large dependency tree of its own. The
 * core made the same call for S3 and signs its own requests. This is the same
 * exercise: an HMAC over a string whose shape Azure documents.
 *
 * The string to sign is positional — thirteen header slots in a fixed order,
 * blank where the header is absent — so it is written out below in that order
 * rather than assembled from a loop, because the empty lines are the part that
 * matters and a loop hides them.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure;

class Signer {

    /**
     * The REST API version the requests declare.
     *
     * It is sent on every request and it decides how Azure reads the signature:
     * from 2014-02-14 onward a zero Content-Length is signed as an empty line
     * rather than as "0", which is the detail that makes a DELETE fail with 403
     * if it is got wrong.
     */
    const VERSION = '2021-08-06';

    /**
     * @var string The storage account name.
     */
    private $account;

    /**
     * @var string The account key, still base64 as Azure gives it.
     */
    private $key;

    /**
     * @param string $account The storage account name.
     * @param string $key     The account key, base64 as Azure gives it.
     */
    public function __construct( string $account, string $key ) {
        $this->account = $account;
        $this->key = $key;
    }

    /**
     * Whether the key is usable at all.
     *
     * Checked once, up front, rather than letting `base64_decode()` return
     * false and `hash_hmac()` sign with an empty key — which produces a
     * perfectly well-formed signature that Azure rejects with 403, and a log
     * line about authorisation rather than about a mistyped key.
     */
    public function isUsable() : bool {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding Azure's own key encoding.
        return '' !== $this->account && false !== base64_decode( $this->key, true );
    }

    /**
     * The Authorization header for one request.
     *
     * @param string                $method   HTTP verb.
     * @param string                $path     Path from the account root, leading slash included.
     * @param array<string, string> $headers  The request's headers.
     * @param array<string, string> $query    Query parameters, if any.
     */
    public function authorization(
        string $method,
        string $path,
        array $headers,
        array $query = []
    ) : string {
        $string_to_sign = implode(
            "\n",
            [
                strtoupper( $method ),
                $headers['Content-Encoding'] ?? '',
                $headers['Content-Language'] ?? '',
                // An empty line, not "0": see VERSION above.
                self::contentLength( $headers ),
                $headers['Content-MD5'] ?? '',
                $headers['Content-Type'] ?? '',
                // Date is empty because x-ms-date carries it, and it is one of
                // the canonicalised headers below.
                '',
                $headers['If-Modified-Since'] ?? '',
                $headers['If-Match'] ?? '',
                $headers['If-None-Match'] ?? '',
                $headers['If-Unmodified-Since'] ?? '',
                $headers['Range'] ?? '',
            ]
        ) . "\n" . self::canonicalHeaders( $headers ) . $this->canonicalResource( $path, $query );

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding Azure's own key encoding.
        $key = (string) base64_decode( $this->key, true );

        $signature = hash_hmac( 'sha256', $string_to_sign, $key, true );

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the header's own encoding, not obfuscation.
        return 'SharedKey ' . $this->account . ':' . base64_encode( $signature );
    }

    /**
     * Content-Length as the signature wants it: blank when there is no body.
     *
     * @param array<string, string> $headers The request's headers.
     */
    private static function contentLength( array $headers ) : string {
        $length = $headers['Content-Length'] ?? '';

        return '0' === $length ? '' : $length;
    }

    /**
     * The x-ms-* headers, lowercased, sorted, one per line.
     *
     * @param array<string, string> $headers The request's headers.
     */
    private static function canonicalHeaders( array $headers ) : string {
        $canonical = [];

        foreach ( $headers as $name => $value ) {
            $lower = strtolower( $name );

            if ( 0 !== strpos( $lower, 'x-ms-' ) ) {
                continue;
            }

            // Values are trimmed and their internal whitespace collapsed,
            // because that is what Azure signs on its side.
            $canonical[ $lower ] = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
        }

        ksort( $canonical );

        $lines = '';

        foreach ( $canonical as $name => $value ) {
            $lines .= $name . ':' . $value . "\n";
        }

        return $lines;
    }

    /**
     * `/account/container/blob`, plus any query parameters, sorted.
     *
     * @param string                $path  Path from the account root.
     * @param array<string, string> $query Query parameters, if any.
     */
    private function canonicalResource( string $path, array $query ) : string {
        $canonical = '/' . $this->account . $path;

        if ( ! $query ) {
            return $canonical;
        }

        $lowered = [];

        foreach ( $query as $name => $value ) {
            $lowered[ strtolower( $name ) ] = $value;
        }

        ksort( $lowered );

        foreach ( $lowered as $name => $value ) {
            $canonical .= "\n" . $name . ':' . $value;
        }

        return $canonical;
    }
}
