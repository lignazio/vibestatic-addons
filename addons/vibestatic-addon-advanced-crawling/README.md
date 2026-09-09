# VibeStatic Add-on: Additional Host Rewriting

Rewrites hosts **other than the WordPress site's own** to the deployment URL.

The core already rewrites the site's own URL — that is `WP2Static\SimpleRewriter`,
and it runs on every processed file. What it cannot know about is the other hosts
a page may point at and that should also become the published site: a CDN or
image host in front of WordPress, a second domain the site answers on, the
internal hostname of a staging box. Left alone, those become links out of the
static site and back into the WordPress installation.

One host per line, without a scheme:

```
cdn.example.com
images.example.com
```

Each is replaced over `https://`, over `http://`, protocol-relative
(`//host`), and with escaped slashes as it appears inside inline JSON. More
specific hosts are rewritten first, so `cdn.example.com` cannot be left with
`cdn.` stuck to the front of the destination URL.

## What became of the add-on this replaces

`wp2static-addon-advanced-crawling` (May 2021) carried its own `Crawler`,
`CrawlQueue`, `Detection` and `Rewriter`: it did not extend the core, it
substituted it, because the core's crawler was sequential and did not follow
links. VibeStatic 8 crawls in parallel, follows links, keeps a crawl cache and
prunes what it has published — substituting it would now be a downgrade.

Of its thirteen options, **nine are core options today**:
`addURLsWhileCrawling`, `crawlChunkSize`, `crawlProgressReportInterval`,
`detectRedirectionPluginURLs`, `fileExtensionsToIgnore`, `filenamesToIgnore`,
`additionalPathsToCrawl`, `crawlConcurrency`, `useCrawlCaching`. Its
Redirection-plugin detection is `WP2Static\DetectRedirectionPluginURLs`. What is
left, and is nowhere in the core, is this.

**And it did not work.** The upstream rewriter built its https pattern as
`'https:// ' . $host` — a space between the scheme and the host — so of the four
patterns it produced, the one for https matched nothing. On any site served over
TLS the feature did nothing at all, quietly. There is a test for it.

The slug stays `wp2static-addon-advanced-crawling`: it keys the row in the
add-ons table and the name of the options table on installations that already
have it.

## Verified

Against a real export, and this one needed no account. A page carrying five
references was published with two extra hosts configured, and the output
checked:

| In the page | In the published copy |
|---|---|
| `https://cdn.example/img.png` | rewritten |
| `http://cdn.example/img.png` | rewritten |
| `//cdn.example/style.css` | rewritten |
| `https://elsewhere.example/x` | left alone |

The `https` row is the one that matters: upstream built its pattern as
`'https:// ' . $host`, with a space in it, so on any site served over TLS the
add-on did nothing at all and said nothing about it. No occurrence of either
configured host was left anywhere in the output.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
