# VibeStatic Add-on: GitHub

Commits the generated site to a GitHub repository — one commit per deploy,
through the Git Data API.

## Configuration

| Setting | Notes |
|---|---|
| Repository | As `owner/name` |
| Access token | A fine-grained personal access token with read and write on this repository's contents. Stored encrypted. |
| Branch | It has to exist already |
| Path within the repository | Optional |
| Commit message | A deploy split across several commits gets a counter appended |

```bash
wp vibestatic github options set githubRepository acme/acme.github.io
```

## Why one commit and not one request per file

The add-on this replaces used the Contents API, which writes one file per
request and needs the file's current blob SHA to overwrite it — a GET and a PUT
per file. On the 4418-URL site the fork was tested against that is close to nine
thousand requests against a limit of five thousand an hour: the deploy could not
finish, and the half that had run was already committed.

Here a batch is: a blob per changed file, one tree on top of the branch's current
tree, one commit, one ref update. Deletions are tree entries with a null SHA,
which is how git removes a path.

## What this replaces

`wp2static-addon-github`, last touched in April 2019 and **not runnable against
any current version of the core**: it extends `WP2Static_SitePublisher`, a class
WP2Static 7 removed, expects the plugin to sit next to a directory called
`static-html-output-plugin`, and dispatches on `$_POST['ajax_action']`.

- Its idea of what had changed lived in
  `wp_uploads/WP2STATIC-GITHUB-PREVIOUS-HASHES.txt`, compared with `crc32`.
  Lose the file and the whole site is re-uploaded; two files that collide are
  never updated again.
- `list($user, $repo) = explode('/', $settings['ghRepo'])` — a repository name
  without a slash was an undefined-offset notice followed by a request to a URL
  with `null` in it.
- No namespace, no `src/`, no tests, no CI, no capability checks.
- The repository also contained `S3Deployer.php`, 418 lines of deployer for a
  different service, and its main plugin file was named
  `wp2static-addon-s3.php`.

## Not verified

Written to GitHub's documented Git Data API; **no request has been made against
a real repository**.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
