# VibeStatic Add-on: Algolia

Makes [WP Search with Algolia](https://wordpress.org/plugins/wp-search-with-algolia/)
work on the static copy of the site.

**It does not index anything.** That other plugin does. This one:

- stores permalinks **relative** rather than absolute, so a search result sends
  the visitor to the published static site and not back to WordPress;
- puts a **search page** into the crawl, so there is something for the form to
  submit to;
- **points search forms at it**, in the generated HTML.

Without WP Search with Algolia installed there is nothing here to adjust, and
the settings page says so.

```bash
wp vibestatic algolia options list
wp vibestatic algolia indices
wp vibestatic algolia objects wp_searchable_posts
```

## What this replaces

`wp2static-addon-algolia`, June 2020.

- **It echoed a `<script>` into the live site's footer** on every page view,
  walking every form on the page and rewriting its action — with the site URL
  interpolated into the JavaScript unescaped and a `console.log` left in. It ran
  for real visitors of the WordPress site, not only for the crawler; search did
  not work at all with JavaScript off; and it moved comment forms and newsletter
  sign-ups to the search page along with the search form. The rewriting happens
  in the generated HTML now, to forms that identify themselves as search forms,
  and nothing is injected into the live site.
- **A variable that does not exist.** `parse_url( $site_path, PHP_URL_PORT )` —
  `$site_path` is not defined in that function. A development site on `:8080`
  kept absolute permalinks, and only there.
- **`"algolia_searchable_post_${post_type}_records"`.** `${var}` interpolation is
  deprecated in PHP 8.2 and removed in PHP 9: sixteen deprecation notices on
  every admin page load with `WP_DEBUG` on.
- **The WP-CLI command was a fatal error.** Registered as a static callable
  while the method was declared non-static.
- **It never registered itself with the core**, so it had a settings page and no
  row on the Add-ons page.
- **An SDK three majors behind.** `algolia/algoliasearch-client-php ^2.6`, which
  does not install on PHP 8.2, for what amounts to two GETs and a paged POST.
  Those are written out now.
- `$hit['post_author']['user_url']` read unguarded: one record indexed without
  an author killed the whole listing.
- Two functions declared in the global namespace.

## Not verified

The read-only client is written to Algolia's documented REST API; **no request
has been made against a real application**.

## Requirements

VibeStatic 9.0 or later, PHP 8.2, WordPress 6.5, and WP Search with Algolia for
the indexing itself. No runtime Composer dependencies.

9.0 because the base this add-on extends, `WP2Static\Addon\`, arrived in
it. On an older core the add-on does not register and says why, rather than
failing during `plugins_loaded`.
