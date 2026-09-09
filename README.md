# VibeStatic add-ons

Ten plugins for [VibeStatic](https://github.com/lignazio/vibestatic), one per
directory under `addons/`. Each installs from a plain zip and **has no runtime
dependencies**: the HTTP client they use is the core's prefixed Guzzle, because
two plugins shipping incompatible copies of the same library under the same
class names break each other.

They need **VibeStatic 9.0 or later**, PHP 8.2 and WordPress 6.5. On an older
core each one refuses to register and says so, rather than fatalling half way
through `plugins_loaded`.

| | What it does |
|---|---|
| `boilerplate` | The reference add-on. Reports what a deploy would send, without sending it. Copy this to write your own. |
| `bunnycdn` | BunnyCDN Edge Storage, with a pull-zone purge |
| `gcs` | Google Cloud Storage |
| `azure` | Azure Blob Storage |
| `github` `gitlab` `bitbucket` | Commit the site to a repository, one commit per deploy |
| `cloudflare-workers` | Workers KV, for a Workers Sites script to serve |
| `algolia` | Makes WP Search with Algolia work on the static copy |
| `advanced-crawling` | Rewrites hosts other than the WordPress site to the deployment URL |

Each has a README saying what it does, what it replaces, and — importantly —
what has **not** been verified against the real service.

## Why one repository

Because the alternative had already failed once. Upstream's add-ons were ten
repositories that had copied the same boilerplate, so one defect in it was ten
defects, and fixing one fixed one. The rewrite reduced that boilerplate to four
small files that were right — and then copied *those* into ten places, nine
hundred and seventy-six identical lines each.

Those four files are now `WP2Static\Addon\` in the core, which every add-on here
extends. What is left is what an add-on actually is: what it is called, which
options it has, and what it does with them. Sixteen thousand nine hundred and
eighteen lines became seven thousand one hundred and ninety-eight.

The rest follows from that. One test harness instead of ten identical copies of
three hundred and sixty-four lines of stubs. One CI configuration. One release
pipeline. One place to say what is verified and what is not.

## Working on them

```bash
composer install
composer test
```

`composer test` is what CI blocks on: validate, lint, PHP 8.2 compatibility,
PHPStan at level `max`, PHPUnit, `verify.php`, and the blocking security and
i18n sniffs.

The core comes in as a **dev dependency**, which is the whole reason PHPStan can
check an add-on's calls into it. In production it is a runtime dependency —
installed alongside, never required — and an add-on ships with no `vendor/` at
all.

```bash
php verify.php
```

Boots all ten against a stubbed WordPress and the core's real base classes:
every add-on wires up, every declared option has a field, every save authorises
before it writes, no secret reaches the database in the clear.

## Releasing one

The tag names the add-on, because ten of them share this repository and
`WP2Static\Addon\Updater` matches releases by that prefix:

```bash
git tag bunnycdn-v1.0.1
git push origin bunnycdn-v1.0.1
```

`.github/workflows/release.yml` checks the tag against the version in the plugin
header, runs the suite, builds the zip and publishes it. A prerelease tag
(`-rc1`, `-beta`) is not offered to production sites.

To build one by hand:

```bash
tools/build_release.sh bunnycdn
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

The add-ons these replace were written by Leon Stafford and released into the
public domain under the Unlicense. That original remains public domain wherever
it stands.
