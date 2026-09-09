# VibeStatic Add-on: Google Cloud Storage

Uploads the generated site to a GCS bucket and removes from it what has left the
site.

## Not verified

**This add-on has not been tested end to end against the real service.**
Try it against a throwaway account before pointing it at a site that
matters.

The token exchange and the JSON API calls are written to Google's documented
shapes and the JWT signing is covered by tests, but **no request has been made
against a real bucket**.

## Configuration

Paste the **service account key file whole** into the settings page. It needs
Storage Object Admin on the bucket. Optional: a path prefix, a predefined ACL
(leave blank on a bucket with uniform access, which refuses per-object ACLs) and
a `Cache-Control` header.

```bash
wp vibestatic gcs options list
```

## What this replaces

`wp2static-addon-gcs`, last touched on `master` in June 2021. (GitHub shows a
commit from December 2024; it is on an unmerged Dependabot branch.)

- **No SDK.** It required `google/cloud-storage ^1.23`, some ninety packages
  including their own Guzzle and PSR-7, in a plugin installed next to other
  plugins that ship their own. The version pinned in 2021 does not install on
  PHP 8.2. This talks to the JSON API and mints its own OAuth2 token from the
  service account — the same call the core made for S3.
- **It never deleted anything.** It walked the processed site and uploaded what
  the deploy cache did not know about, which finds new and changed files and can
  never find a page WordPress no longer has. Deletion is what
  `DeployCache::plan()` is for, and it is used here.
- **The key was a path typed into a text box**, opened by the plugin — a file
  read whose target the operator controls, which breaks whenever the site moves
  or the deploy runs as a different user.
- Files were read into memory whole; four queries interpolated the table name;
  the settings form had no capability check.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5, `ext-openssl` (WordPress
requires it anyway). No runtime Composer dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
