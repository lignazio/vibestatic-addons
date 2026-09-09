# VibeStatic Add-on: Cloudflare Workers KV

Writes the generated site into a Workers KV namespace for a worker to serve.
Each path becomes a key holding the file's bytes, plus a `<path>_ct` key holding
its content type — the layout a Workers Sites script reads.

> **Before you choose this.** Cloudflare has moved static-site hosting to Pages
> and to Workers static assets; KV-backed Workers Sites is the older
> arrangement. This add-on still targets KV, because that is what an existing
> worker script expects. A new site is better served by Pages.

## Not verified

**This add-on has not been tested end to end against the real service.**
Try it against a throwaway account before pointing it at a site that
matters.

Written to Cloudflare's documented Workers KV bulk endpoints; **no request has
been made against a real account**.

## Configuration

Account ID, KV namespace ID, and an API token with **Workers KV Storage: Edit**.
`Store /page/index.html as /page/` is on by default and is what a worker asks KV
for when a browser requests a directory URL.

```bash
wp vibestatic cloudflare-workers options list
```

## What this replaces

`wp2static-addon-cloudflare-workers`, the most recently maintained of the twelve
(February 2022) and the most tangled.

- **Three impossible dependencies, now gone.** It required
  `leonstafford/wp2staticguzzle`, `leonstafford/wp2static` and `latte/latte`, and
  declared `replace` on `guzzlehttp/guzzle` — that is, it insisted on the
  abandoned upstream's fork of the plugin and of its HTTP client, and told
  Composer that installing it satisfied Guzzle for everything else in the
  project. None of those constraints can be met today. The HTTP client is now
  the core's prefixed Guzzle, the templating is the shared settings page, and
  the core is a runtime dependency rather than a Composer one.
- **It never deleted anything.** The namespace kept every page the site had ever
  had, and a worker serving from it went on serving them.
- **It recorded failures as successes.** Every file went into the DeployCache
  whatever the API answered, so a rejected batch was remembered as deployed and
  never retried. Worse, the singular path issued two PUTs per file and read
  `success` off the *second* response to decide whether the *first* had worked.
- **A gateway error was a fatal error.** `$result->success` was read off the
  decoded body without checking that it had decoded; a 502 returns HTML.
- **Its two modes wrote different keys.** The bulk path mapped
  `/page/index.html` to `/page/` and the singular one did not, so switching
  between them left the namespace holding both.
- A 1047-line MIME table; no deletion; `latte` templates where every other
  add-on used WordPress's.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
