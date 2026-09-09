# VibeStatic Add-on: GitLab

Commits the generated site to a GitLab project — one commit per deploy, through
the commits API.

## Not verified

**This add-on has not been tested end to end against the real service.**
Try it against a throwaway account before pointing it at a site that
matters.

Written to GitLab's documented commits and repository-tree APIs; **no request
has been made against a real project**.

## Configuration

| Setting | Notes |
|---|---|
| Project | Its full path (`group/subgroup/site`) or numeric ID |
| Access token | A project access token with the `api` scope and the Maintainer role. Stored encrypted. |
| Branch | It has to exist already |
| Path within the project | Optional |
| API URL | For a self-managed GitLab. Blank for gitlab.com. |

```bash
wp vibestatic gitlab options list
```

## How it works

GitLab's commits API takes a list of actions — create, update, delete — and
applies them as one commit, so a whole batch is one request. The one thing it
will not do is upsert: `create` fails on a path that exists and `update` on one
that does not. The branch's file list is read once at the start and each path
gets the action that fits. A delete for a path GitLab does not have is skipped
rather than sent, because it would fail the whole commit and take the uploads in
the same batch with it.

## What this replaces

`wp2static-addon-gitlab`, April 2019, and like its GitHub sibling **not runnable
against any current version of the core**: `WP2Static_SitePublisher`,
`$_POST['ajax_action']`, a crc32 hash file in the uploads directory, no
namespace, no tests, no CI, no capability checks.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
