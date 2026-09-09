# VibeStatic Add-on: Boilerplate

**The reference add-on.** It deploys nothing: enabled as the deployer, it asks
the core what a real deploy would send and remove, and writes that to the log.
That makes it useful on its own — it is the answer to "what would publishing do
right now?" — and it is the shape to copy when writing an add-on of your own.

## Writing an add-on from this

Copy the directory, rename the namespace, and change `src/Controller.php`: the
slug, the name, the options, the fields. Everything else is shared and is meant
to be copied unchanged:

| File | What it is |
|---|---|
| `src/AddonController.php` | Registering with the core, the settings page, the authorised save, the deploy dispatch |
| `src/Options.php` | The options table: prepared SQL, an idempotent seed, an upsert, encrypted secrets |
| `src/SettingsPage.php` | The settings form, rendered from the declaration so nothing goes out unescaped |
| `src/OptionsCommand.php` | `wp vibestatic <add-on> options get\|set\|list` |
| `autoload.php` | Four lines. There are no runtime dependencies to install. |

The add-on has **no Composer dependencies at runtime**. It uses the core's
prefixed Guzzle rather than shipping a second copy — two plugins shipping
incompatible copies of the same library under the same class names is how one
of them breaks the other.

## What this replaces

`wp2static-addon-boilerplate`, whose 352-line Controller was copied into ten
repositories and then edited. The defects that came with it, each of which this
one does not have:

- **No capability check.** `saveOptionsFromUI()` checked the nonce and nothing
  else, on a form that stores credentials. A nonce says where a request came
  from, not who sent it.
- **Unescaped output.** The settings view echoed every value raw, including a
  decrypted secret into a `value` attribute.
- **A seed that duplicated rows.** A plain `INSERT` into a table with no unique
  key, so every activation added a second row per option.
- **A save that did nothing.** `$wpdb->update()` updates no rows when the row is
  absent, and reports success either way.
- **Interpolated table names**, in every query and in `uninstall.php`.
- **A deployer that lied.** The example decided success with `rand( 0, 1 )` and
  wrote to the DeployCache regardless — teaching by example that a file is
  recorded as sent without knowing whether it arrived.
- **Options read under the wrong names.** `Boilerplate::__construct()` read
  `an_encrypted_option` where the seed wrote `anEncryptedOption`, and
  `boilerplateStorageZoneName`, which is a BunnyCDN option that never existed
  here. It then logged the decrypted secret.
- Header version `1.0-alpha-001`, constant `1.0-alpha-006`.

## Nothing to verify

This add-on sends nothing anywhere: that is what it is for. There is no service
to test it against, which is also why it is the one to copy — you can watch what
a deploy *would* do before writing the part that does it.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in it. On an older core the add-on does not register and says why, rather than failing during `plugins_loaded`.

## Development

```bash
composer install
composer test
```
