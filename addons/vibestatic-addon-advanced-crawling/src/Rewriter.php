<?php
/**
 * Rewrite extra hosts to the deployment URL.
 *
 * The core already rewrites the WordPress site's own URL — that is
 * `WP2Static\SimpleRewriter`, and it runs on every processed file. What it
 * cannot know about is the *other* hosts a page may refer to and that should
 * also become the published site: a CDN or image host in front of WordPress, a
 * second domain the site answers on, the internal hostname of a staging box.
 * Left alone, those turn into links out of the static site and back to the
 * WordPress installation.
 *
 * That is the one thing worth keeping out of the add-on this replaces. The rest
 * of it — the crawler, the crawl queue, the redirect detection, the ignore
 * lists, and nine of its thirteen options — is in the core now.
 *
 * @package WP2StaticAdvancedCrawling
 */

namespace WP2StaticAdvancedCrawling;

use WP2Static\CoreOptions;
use WP2Static\URLHelper;

class Rewriter {

    /**
     * @var Options|null Set by the Controller before the hooks fire.
     */
    private static $options = null;

    public static function setOptions( Options $options ) : void {
        self::$options = $options;
    }

    /**
     * Rewrite one processed file in place.
     *
     * @param string $filename A file in the processed site.
     */
    public static function rewrite( string $filename ) : void {
        $contents = file_get_contents( $filename );

        if ( false === $contents || '' === $contents ) {
            return;
        }

        $rewritten = self::rewriteFileContents( $contents );

        if ( $rewritten === $contents ) {
            // Nothing matched. Not writing the file back saves a write per file
            // on a site with no additional hosts configured, which is most of
            // them — the add-on this replaces wrote every file every time.
            return;
        }

        file_put_contents( $filename, $rewritten );
    }

    /**
     * The rewriting itself, so it can be tested without a filesystem.
     *
     * @param string $contents The file's contents.
     */
    public static function rewriteFileContents( string $contents ) : string {
        if ( '' === $contents ) {
            return '';
        }

        // The core's own switch, honoured here too: somebody who has turned URL
        // rewriting off does not want this add-on rewriting either.
        if ( 1 === (int) CoreOptions::getValue( 'skipURLRewrite' ) ) {
            return $contents;
        }

        $hosts = self::hosts();

        if ( ! $hosts ) {
            return $contents;
        }

        /** @var mixed $destination */
        $destination = apply_filters(
            'wp2static_set_destination_url',
            CoreOptions::getValue( 'deploymentURL' )
        );

        $destination = untrailingslashit( is_string( $destination ) ? $destination : '' );

        if ( '' === $destination ) {
            return $contents;
        }

        $destination_rel = URLHelper::getProtocolRelativeURL( $destination );
        $destination_escaped = addcslashes( $destination_rel, '/' );

        $search = [];
        $replace = [];

        foreach ( $hosts as $host ) {
            $host_rel = URLHelper::getProtocolRelativeURL( 'http://' . $host );

            /*
             * `https://` with no space in it.
             *
             * The add-on this replaces had `'https:// ' . $host` — a space
             * between the scheme and the host — so of the four patterns it
             * built, the one for HTTPS matched nothing. Every https:// link to
             * an additional host survived rewriting, which on any site served
             * over TLS is all of them: the feature did not work at all, and did
             * so quietly.
             */
            $search[] = 'https://' . $host;
            $search[] = 'http://' . $host;
            $search[] = $host_rel;
            $search[] = addcslashes( $host_rel, '/' );

            $replace[] = $destination;
            $replace[] = $destination;
            $replace[] = $destination_rel;
            $replace[] = $destination_escaped;
        }

        return str_replace( $search, $replace, $contents );
    }

    /**
     * The configured hosts, one per line, cleaned up.
     *
     * A host is taken as a bare hostname; a full URL typed in by mistake is
     * reduced to its host rather than being used as a literal string, because
     * `https://https://cdn.example.com` is the kind of result that is hard to
     * trace back to a settings field.
     *
     * @return string[]
     */
    public static function hosts() : array {
        if ( null === self::$options ) {
            return [];
        }

        $lines = preg_split( '/\R/', self::$options->get( 'additionalHostsToRewrite' ) );

        if ( ! $lines ) {
            return [];
        }

        $hosts = [];

        foreach ( $lines as $line ) {
            $host = trim( $line );

            if ( '' === $host ) {
                continue;
            }

            if ( false !== strpos( $host, '//' ) ) {
                $parsed = parse_url( $host, PHP_URL_HOST );

                if ( ! is_string( $parsed ) || '' === $parsed ) {
                    continue;
                }

                $host = $parsed;
            }

            $hosts[] = rtrim( $host, '/' );
        }

        // Longest first: rewriting `example.com` before `cdn.example.com` would
        // leave `cdn.` stuck to the front of the destination URL.
        usort(
            $hosts,
            static function ( string $a, string $b ) : int {
                return strlen( $b ) <=> strlen( $a );
            }
        );

        return array_values( array_unique( $hosts ) );
    }
}
