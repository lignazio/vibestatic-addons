# VibeStatic Add-on: BunnyCDN

Uploads the generated site to a BunnyCDN Edge Storage zone, removes what has
left the site, and purges the pull zone.

## Configuration

| Setting | Notes |
|---|---|
| Storage zone name | The Edge Storage zone |
| Storage zone password | Its **FTP & API password**, from the zone's FTP & API Access panel. Stored encrypted. |
| Storage region code | `ny`, `la`, `sg`, `syd`, `uk`, `se`, `br`, `jh`… Blank for the default Falkenstein region. |
| Path within the zone | Optional prefix |
| Pull zone ID | Optional; needed only for the cache purge |
| Account API key | Optional; needed only for the cache purge. Stored encrypted. |

```bash
wp vibestatic bunnycdn options list
wp vibestatic bunnycdn options set bunnycdnStorageZoneName my-zone
```

## What this replaces

`wp2static-addon-bunnycdn`, last touched in May 2020.

- **It never recorded anything as deployed.** `DeployCache::addFile()` was
  commented out, with a `TODO: Look for 201 status from Bunny` beside it: every
  deploy re-uploaded the whole site, for ever.
- **It never deleted anything.** A page removed in WordPress stayed served.
- **It could not tell success from failure.** It decoded the JSON body and took
  any truthy value as success, so a 401 answering `{"Message":"Unauthorized"}`
  counted as an upload.
- **The constructor made network requests**, listing every storage zone on the
  account to find the zone's password — so the add-on needed an account-wide key
  where a zone-scoped one does, and a wrong zone name surfaced as
  `Attempt to read property "Password" on null`. The zone password is asked for
  directly now.
- **The cache purge was eighty lines of commented-out curl** under a live
  `error_log('calling cache purge')`. It logged that it was purging and did
  nothing.
- **The region was hard-coded**, so a zone in New York or Singapore was written
  to through the wrong endpoint.
- `urlencode()` was applied to whole paths, turning every `/` into `%2F`.
- Files were read into memory whole before being sent.
- No capability check on the settings form; nothing escaped in the view;
  `error_log( print_r( $result, true ) )` in the upload loop.
- The WP-CLI command was registered as a static callable while the method was
  declared non-static: on PHP 8 it was a fatal error the first time it ran.
- Undeclared dynamic properties, deprecated as of PHP 8.2.

## Not verified

The endpoints here are written to BunnyCDN's documented Edge Storage and pull
zone APIs, but **no request has been made against a real account**. Check the
first deploy against a throwaway zone.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5. No runtime dependencies: the
HTTP client is the core's.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
