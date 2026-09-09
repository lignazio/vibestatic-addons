# Changelog

Ten plugins share this repository and, so far, share a version: they are
released together and the same change touches all of them. When that stops
being true, this file grows a section per add-on.

## 1.1.0 (2026-09-09)

### Changed

- **Each add-on carries its own `Updater`.** Until now they called
  `\WP2Static\Addon\Updater::register()`, in the core. That could not last: the
  core is being submitted to the wordpress.org plugin directory, and guideline 8
  forbids a plugin hosted there from "serving updates or otherwise installing
  plugins, themes, or add-ons from servers other than WordPress.org's". A core
  installed from the directory therefore ships an inert shim at that class name
  — enough that an add-on at 1.0.0 does not fatal, and nothing more — so an
  add-on that kept asking the core would quietly stop receiving updates the day
  its user installed the core from WordPress instead of from GitHub.

  The class is written once, in `tools/updater-template.php`, generated into all
  ten by `tools/sync_updater.php`, and `verify.php` fails if any copy has
  drifted from the template. Ten copies is what the ancestral add-ons had and
  what the rewrite undid — but those were copied by hand and then diverged, one
  defect in the boilerplate becoming ten. Generated copies with an equality
  check cannot. There is nowhere shared to put this that ships with all ten:
  each add-on is a separate plugin and its zip has to stand alone.

  Behaviour does not change. Each still offers only the release whose tag starts
  with its own prefix, and one HTTP request still serves every add-on installed:
  the release list is cached in a transient keyed by the repository, so the
  first of the ten to ask fetches it and the other nine read it.

- **The eight add-ons never tried against the real service say so at the top of
  their README**, in the same words their settings page uses, rather than four
  sections down. `algolia`, `azure`, `bitbucket`, `bunnycdn`,
  `cloudflare-workers`, `gcs`, `github`, `gitlab`: the requests are written to
  each service's documented API and the logic is covered by tests, but no deploy
  has been watched end to end. Try one against a throwaway account before
  pointing it at a site that matters.

  `advanced-crawling` has been tried, and `boilerplate` sends nothing anywhere.

### Note on upgrading

An add-on at 1.0.0 keeps working on a core installed from GitHub, and keeps
working — without updating itself — on a core installed from wordpress.org. It
will not offer 1.1.0 to itself on the latter, because on that core the class it
asks is the shim. Install 1.1.0 by hand once, and it looks after itself from
then on.
