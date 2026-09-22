# Tests

A dependency free harness for the parts of the plugin that are pure logic and
safety critical: the way a retention rule is resolved, and the way the sweep
decides what to delete.

```sh
php tests/test-retention.php
```

`wp-stubs.php` declares just enough of WordPress — options, filters, post type
support, and a `wpdb` that answers from fixtures instead of MySQL — for the
plugin's own classes to run outside a site. What it covers:

* the keep floor beating the age threshold, so a post that was last edited
  years ago keeps its newest revisions instead of losing everything;
* a dry run reporting exactly what a real run then deletes, and deleting
  nothing itself;
* the network / site / post type merge on multisite, including a site override
  being ignored while the network forbids overrides;
* batching and the cursor, so a sweep resumes rather than restarts;
* `Settings::sanitize()` clamping every number and dropping unknown post types.

Anything that needs a real database or a real WordPress — the SQL the sweep
runs, autosave exclusion, cron rescheduling, the admin screens — is verified on
an install instead. The plugin ships a Playground blueprint for that.

This folder never ships: it is excluded by `.distignore` and `.gitattributes`.
