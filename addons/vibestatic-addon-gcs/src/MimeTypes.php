<?php
/**
 * What to tell the destination a file is.
 *
 * It matters more than it looks: an object store keeps whatever `Content-Type`
 * it is given and serves it back unchanged, so a page uploaded as
 * `binary/octet-stream` is a page the browser offers to download instead of
 * rendering. There is no server at the other end to work it out from the
 * extension.
 *
 * **This file is identical in every VibeStatic add-on that needs it.** The
 * add-ons this replaces each carried a 1029-line table covering every type IANA
 * has registered — four copies of it across the repositories, one of them in an
 * add-on that never looked a MIME type up at all. A generated static site
 * contains pages, stylesheets, scripts, fonts, images, feeds and the occasional
 * download, so this covers those and answers `application/octet-stream` for
 * anything else, which is the honest answer for a file nobody anticipated.
 *
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS;

class MimeTypes {

    /**
     * Extension => type.
     *
     * `charset=UTF-8` on the text types on purpose: without it a browser falls
     * back to its own default encoding, and an accented character on a page
     * that carries no `<meta charset>` of its own comes out wrong.
     *
     * @var array<string, string>
     */
    const TYPES = [
        'atom' => 'application/atom+xml',
        'avif' => 'image/avif',
        'css' => 'text/css; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'eot' => 'application/vnd.ms-fontobject',
        'gif' => 'image/gif',
        'gz' => 'application/gzip',
        'htm' => 'text/html; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'md' => 'text/markdown; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'otf' => 'font/otf',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'rss' => 'application/rss+xml',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=UTF-8',
        'wasm' => 'application/wasm',
        'webm' => 'video/webm',
        'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'xml' => 'application/xml',
        'xsl' => 'application/xslt+xml',
        'zip' => 'application/zip',
    ];

    public static function forPath( string $path ) : string {
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        return self::TYPES[ $extension ] ?? 'application/octet-stream';
    }
}
