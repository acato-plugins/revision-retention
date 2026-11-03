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
* when `Scheduler::sync()` books the next sweep, and what `Scheduler::advance()`
  stores, including a fresh install not sweeping on its first save;
* a restart or deactivation marking the abandoned log entry as stopped;
* `CronHealth` telling a stalled cron from a quiet site that just woke it;
* when the rating notice is due, the asset lookup with and without a build,
  the sites a network sweep may touch, and what progress is counted over.
* the sweep lock, so the schedule, the screen and WP-CLI never run a real
  batch at the same time, and a lock left by a request that died is taken over;
* a revision WordPress refused to delete not being counted as removed;
* `Upgrade` creating the options read on every request, autoloaded, without
  touching saved settings;
* a 1.5.0 site updated in place: the bootstrap boots on its data, and the
  class names up to 1.5.0 still resolve to the classes that replaced them.

Anything that needs a real database or a real WordPress — the SQL the sweep
runs, autosave exclusion, the admin screens — is verified on
an install instead. The plugin ships a Playground blueprint for that.

This folder never ships: it is excluded by `.distignore` and `.gitattributes`.
