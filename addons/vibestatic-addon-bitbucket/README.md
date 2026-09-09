# VibeStatic Add-on: Bitbucket

Commits the generated site to a Bitbucket repository — one commit per deploy,
through the `src` endpoint.

## Configuration

| Setting | Notes |
|---|---|
| Repository | As `workspace/name` |
| Username | The Bitbucket username, not the email address |
| App password | With **Repositories: Write**. The account password has not worked on the API since 2022. Stored encrypted. |
| Branch | It has to exist already |
| Path within the repository | Optional |

```bash
wp vibestatic bitbucket options list
```

## How it works

Bitbucket's `src` endpoint takes a multipart form: each part whose name is a
path becomes that file's new contents, and a repeated `files` field names paths
to delete. Both happen in one commit, and it upserts — so, unlike GitLab, there
is no need to know first which paths the branch already has. Deletions ride in
the same commit as the uploads, so a rename lands once and the repository never
goes through a state where the old path is gone and the new one has not arrived.

## What this replaces

`wp2static-addon-bitbucket`, April 2019, and **not runnable against any current
version of the core**: `WP2Static_SitePublisher`, `$_POST['ajax_action']`, a
crc32 hash file in the uploads directory, no namespace, no tests, no CI, no
capability checks. Its plugin header described it as "AWS Bitbucket".

## Not verified

Written to Bitbucket Cloud's documented `src` endpoint; **no request has been
made against a real repository**.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
