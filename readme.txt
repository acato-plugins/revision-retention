=== Revision Retention ===
Contributors: acato, paulacato, frankabeekman
Tags: revisions, database, cleanup, performance, post types
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep the newest revisions of every post and drop the ones older than a threshold you set. Per post type, on a schedule or from WP-CLI.

== Description ==

WordPress keeps post revisions forever unless you set `WP_POST_REVISIONS`, and even then the only thing you can set is a count that applies to the whole site and only takes effect while a post is being saved. A site that has been running for a few years ends up with tens of thousands of revisions nobody will ever open, slowing down queries and padding out every backup.

This plugin gives revisions a retention policy instead:

* **Always keep the newest few.** A floor that is never crossed, however old those revisions are.
* **Remove what is older than a threshold.** Anything past the floor and past the age you pick, from a week up to five years.
* **Set both per post type.** Long history for posts, a short leash on pages, nothing at all for a custom post type that churns.
* **Clean up what is already there.** A scheduled sweep works through the site in batches, so an old site with a large `wp_posts` table is cleaned up over several quiet runs rather than one heavy one.

= The two numbers work together =

This is the part worth understanding, because it is what keeps the plugin safe to switch on:

* **Always keep** is a floor. The newest N revisions of a post are never deleted, whatever their age.
* **Remove older than** is a ceiling, and it only applies to what is left above the floor.

So a page that was last edited three years ago, with forty revisions and a policy of *keep 5, remove older than 365 days*, keeps its five most recent revisions and loses the other thirty-five. It does not lose its whole history just because all of it is old.

Setting **Always keep** to -1 means every revision is retained and that post type is never swept, whatever the age threshold says.

Autosaves are never touched. An autosave is somebody's unsaved work rather than a point in the history.

= Enable revisions where WordPress does not =

Some post types ship without revision support, so nothing is recorded at all. The settings screen lists every eligible post type with a checkbox to switch revisions on for it. It applies from the next time such a post is saved.

= On a schedule =

The sweep runs in the background at an interval you choose, from every hour to once a month, or on the days of the week you tick: once at the hour you pick, say Monday and Wednesday at 03:00 in the site's timezone, or every hour or every few hours from that hour until midnight. Every run books the next one itself: another batch in a minute while there is work left, or the next full run at your interval once the site is clean. A sweep that gets interrupted continues where it stopped instead of starting over.

There are also **Preview** and **Run one batch now** buttons on the settings screen. Preview reports what the policy would remove without deleting anything.

= A log of every sweep =

The log is on from the start, and every sweep that removes revisions gets an entry on the **Logs** tab: whether the schedule, somebody on the settings screen, WP-CLI or the plugin's run-sweep ability ran it, who that was, how many revisions it removed from how many posts, and per post type. A sweep spread over many batches is one entry. The tab shows them on a timeline, with a bar per day for the last month. Entries are kept for as long as you choose, from a week to a year, and older ones are deleted daily. Previews are never logged, and the log can be switched off on the Logs tab.

Each site keeps its own log, next to its own revisions. On multisite the network screen's **Logs** tab shows every site's log together on one timeline, with each entry naming the site it ran on and linking to that site's own log.

= A walkthrough of the settings =

The first time somebody who manages the plugin opens the WordPress admin after installing it, a card beside the **Settings** menu offers a short walkthrough of the settings screen: the numbers at the top, the policy, the post types, the schedule, sweeping now, the log, with an example of a log entry, and the Dashboard widget. After an update that adds to the screen, the walkthrough comes back with only what is new, so it reads as what changed. **Not now** puts the card away until the next update that brings something new, and the walkthrough can always be taken again from the link at the foot of the settings screen.

= Light, dark and high contrast =

The settings screen, the Dashboard widget and the walkthrough follow the contrast setting of the system: more contrast, less contrast, or Windows High Contrast and other forced colours. On its own settings screen the plugin also follows the system's dark mode, the whole page included. Text meets WCAG 2.2 AA contrast in every one of them, on every admin colour scheme.

= From WP-CLI =

    # What is the policy, and what has it still got to do?
    wp revision-retention status

    # The rule and the stored revisions of every post type.
    wp revision-retention post-types

    # See what would go, without deleting anything.
    wp revision-retention run --dry-run

    # Apply the policy to pages only.
    wp revision-retention run --post-type=page --yes

= On multisite =

The network admin sets the defaults for every site, under **Network Admin → Settings → Revision Retention**. A switch there decides whether sites may override them. When they may, each site's own screen inherits every field it leaves empty and shows the network value behind it; when they may not, that screen reports the policy and nothing more.

Settings travel the other way too. Once you have a policy on one site that you want everywhere, **Save as network default** on that site's screen makes it the default for the whole network and drops the site's own copy, so the site simply inherits what it already had. Sites with settings of their own keep them; only the defaults underneath move. The button only appears for a network administrator, since it decides what every other site starts from.

When the network keeps the policy to itself, a site's screen can still **Preview** — reading what the policy would take away costs nothing — but only a network administrator can actually sweep that site. Otherwise a network could switch the scheduled sweep off and a site administrator could still delete by hand, which is the opposite of what locking the policy is for.

The network screen can **Preview every site**, walking the whole network and reporting what the policy would remove without deleting anything, **Sweep every site now**, which does the same and deletes as it goes, site after site, with a progress bar and an entry in each site's log, and **Schedule a sweep on every site**, which leaves the work to WP-Cron on each site instead. Both pass over the sites that have switched the sweep off. The sweep itself always runs per site, under whatever policy that site ends up with.

Its **Logs** tab brings the log of every site together, so you can see what was removed across the network, where, and by whom, without opening each site in turn.

= For developers =

    // Never touch these posts, whatever the policy says.
    add_filter( 'revision_retention_excluded_posts', fn( $ids ) => [ ...$ids, 42, 108 ] );

    // Decide the rule for a post type in code.
    add_filter( 'revision_retention_rule', function ( $rule, $post_type ) {
        return 'page' === $post_type ? new Retention_Rule( 20, 90 ) : $rule;
    }, 10, 2 );

    // Change which post types can have a policy at all.
    add_filter( 'revision_retention_eligible_post_types', $callback );

= Abilities (WordPress 7.1) =

On WordPress 7.1 and later the plugin registers its work with the Abilities API, in the `revision-retention` category:

* `revision-retention/get-status`: revisions stored, the policy, the next sweep, whether WP-Cron runs, and where the current sweep stands.
* `revision-retention/get-post-types`: per post type, the revisions it stores and the rule that applies to it.
* `revision-retention/preview-sweep`: what a sweep would remove, per post type, without deleting anything.
* `revision-retention/get-sweep-log`: the most recent sweeps, where each came from and what it removed.
* `revision-retention/run-sweep`: removes one batch of revisions now, under the same rules as **Run one batch now**, and is logged as such.

They work on the current site and ask for `manage_options`, the capability that also opens the settings screen; on a network that keeps the policy to itself, run-sweep is for network administrators only. They are not public and not on the REST API, so only code running on the site can call them, and none of them returns who ran a sweep, user IDs or post titles.

    $status = wp_get_ability( 'revision-retention/get-status' )->execute();

Deletion goes through `wp_delete_post_revision()` rather than a bulk query, so post meta and term relationships are cleaned up the way WordPress expects and other plugins see the hooks they are listening for.

Development happens at [github.com/acato-plugins/revision-retention](https://github.com/acato-plugins/revision-retention).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through **Plugins → Add New**.
2. Activate the plugin through the **Plugins** screen. On multisite you can network activate it.
3. Set the policy under **Settings → Revision Retention**, or **Network Admin → Settings → Revision Retention** on multisite.
4. Press **Preview** to see what the policy would remove before letting it run.

== Frequently Asked Questions ==

= Will this delete the revision I am about to restore? =

Not if it is one of the newest ones you asked to keep. The **Always keep** floor is applied per post and is never crossed on age. Press **Preview** first if you want to see the exact number before anything is deleted.

= Can I get deleted revisions back? =

No. Revisions are deleted the same way WordPress deletes them, permanently. That is why Preview and the `--dry-run` flag exist, and why the plugin ships with a conservative default of keeping the newest five.

= Does it touch autosaves? =

No. Autosaves are excluded from every query the sweep makes.

= Why has nothing been removed yet? =

A sweep only has work to do for a post type with an age threshold. With **Remove older than** set to Never the plugin only caps the count on save, which is WordPress's own behaviour, and there is nothing to clean up afterwards. `wp revision-retention post-types` shows the rule in effect for each one.

= My site is huge. Will the sweep time out? =

It works through a configurable number of posts per batch and books the next batch itself, so each run stays short. Lower **Posts per batch** if the runs are still too heavy.

= I enabled revisions for a post type but see nothing. =

Revisions start being recorded from the next time such a post is saved. Nothing can recover a history that was never stored.

= Does the count limit work without the sweep? =

Yes. The **Always keep** number is handed to WordPress through `wp_revisions_to_keep`, so it caps revisions as posts are saved whether or not a sweep ever runs.

= Does the plugin send anything to an AI service? =

No. Its abilities only run for code on the site itself, they are not on the REST API or offered to AI clients, and what they return holds counts, settings and dates: never who ran a sweep, user IDs or post titles. A request or answer that carries an e-mail address or another personal-data field is refused.

= Where are the translations? =

Translations are managed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/revision-retention/) and are downloaded by WordPress automatically. The plugin ships a `.pot` template for translators, but no locale files of its own.

== Screenshots ==

1. The settings screen, with the policy in force and the time until the next sweep at the top, and every post type's rule and stored revisions below it.
2. Preview reporting what the policy would take from each post, and what each post keeps, before anything is deleted.
3. The schedule: on which days and at what time the sweep runs, whether WP-Cron is running, how many posts it looks at per batch, and the most it may delete in one.
4. The log as a timeline: a bar per day for the last month, and every sweep with who ran it, what it removed per post type, and whether it finished.
5. The network's Logs tab, which shows every site's log together and names the site each sweep ran on.
6. The Dashboard widget: the sweeps of the last days, weeks or months, for this site or the whole network, and when the next one is due.

== Changelog ==

= 1.7.0 =
* A walkthrough of the settings screen. The first time somebody who manages the plugin opens the admin, a card beside the **Settings** menu offers it; after an update it comes back with only what is new, and it can always be taken again from the link at the foot of the settings screen.
* The settings screen, the Dashboard widget and the walkthrough follow the system's contrast setting, Windows High Contrast and other forced colours included, and the settings screen follows its dark mode.
* On WordPress 7.1 and later, the plugin registers its status, post types, a preview, the log and a sweep with the Abilities API. They ask for `manage_options`, are not public and not on the REST API, and never return who ran a sweep, user IDs or post titles.
* Hardening: the network's booking form has a nonce of its own, the walkthrough checks the user's capability before the nonce, and a swept post is only linked when its edit link is an http or https address.

= 1.6.0 =
* A sweep runs once at a time on a site. When the schedule, **Run now** and WP-CLI met on the same site, they could work through the same batch together and count its revisions twice. Now whichever comes second waits: the schedule tries again a minute later, and the screen and WP-CLI say another sweep is running.
* A revision that could not be deleted is no longer counted as removed.
* A scheduled batch books the next one before it starts, so a batch that dies halfway no longer stops the schedule on a site nobody logs in to.
* When the log table cannot be created, the plugin tries again on the next sweep, rather than writing to a table that is not there from then on.
* Uninstalling the plugin clears its scheduled events on every site of a network, also when its data is kept.
* Sweeps the schedule ran show as run by the schedule in the log, never by whoever happened to visit the site at the time.
* Fewer database queries: the settings are autoloaded from the start, the revisions of a batch are read 25 posts at a time instead of post by post, the revision counts are read once per screen, and the network's Dashboard widget keeps its overview while sweeps are under way.
* The sweep buttons keep the keyboard focus while a sweep runs, the WordPress spinner shows beside them, and the outcome is read out by screen readers once. Status text, pills, the progress bar and focus rings meet WCAG 2.2 AA contrast on every admin colour scheme. Tabs answer Home and End.
* Without JavaScript, the Dashboard widget shows both views and every period, and the settings screen every post type, instead of controls that do nothing.
* The countdowns pause while the browser tab is in the background.
* Code written against the class names up to 1.5.0, `Acato\RevisionRetention\Settings` for instance, keeps working: the classes moved to `Acato\RevisionRetention\Admin` and `Acato\RevisionRetention\Includes`, and the old names are aliases of the new ones.
* For developers: the plugin's classes hook themselves in through `::instance()`. Code that created them with `new`, or called their `register()` methods, has to use `::instance()` instead. The `revision_retention_rule`, `revision_retention_eligible_post_types` and `revision_retention_excluded_posts` filters are unchanged.

= 1.5.0 =
* The time until the next sweep counts down live on the **Sweep** tab, and so does each event's next run under **Cron events** on the **Schedule** tab. Under an hour it shows minutes and seconds, in the site's own language, and once the time has passed it says how long ago it was due.
* The numbers at the top of the settings screen include the time until the next sweep.

= 1.4.0 =
* **How often** offers every 4 hours, every 12 hours (twice a day) and every 14 days, next to the hourly, daily, weekly and monthly sweeps it already had.
* **How often** can also run on chosen days of the week, say Monday and Wednesday: once at a time of day you pick to the minute, every hour at so many minutes past it, or every few hours from a time until midnight. Times are in the site's timezone, and on a network a site that inherits the schedule inherits its days and times too. `wp revision-retention status` shows the days and the times.
* The Dashboard widget says when the next cleanup is planned, today, tomorrow or on a day, and when one is overdue. When WP-Cron is not running, for instance because `DISABLE_WP_CRON` is set without a server cron job behind it, the widget says so at the top, with a link to the **Schedule** tab.
* The network's Dashboard widget does the same for the whole network: the next cleanup on any site, how many sites are overdue, and the WP-Cron warning.
* The widget's chart switches between **Daily**, **Weekly** and **Monthly** bars. It starts on the one that suits the schedule, a bar per week for a weekly sweep and per month for a monthly one, and remembers the choice in the browser. Weeks and months go back as far as the log is kept.
* Every bar in the chart, in the widget and on the **Logs** tab, shows what it covers and how many revisions went, when pointed at, focused or clicked. The arrow keys move between the bars.
* Times always show with a colon, 12:26 rather than 12.26, in the site's own 12 or 24 hours.
* The widget and the chart read at WCAG 2.2 AA contrast on every admin colour scheme and with colour blindness, and draw themselves in the system's colours under Windows High Contrast. The bars can be reached with any pointer without hitting a few pixels, a day with nothing removed is only drawn, and the tooltip can be pointed at and closed with Escape.
* On a network that holds a single published site, a network administrator's widget no longer shows a Network tab next to the Site one: the network is the site.

= 1.3.1 =
* The plugin reports its version as 1.3.1. The 1.3.0 release still said 1.2.0 in its header and readme, so it showed and updated as 1.2.0.

= 1.3.0 =
* The **Schedule** tab says whether WP-Cron actually runs on the site, and warns under **Run automatically** when scheduled events are overdue, for instance because `DISABLE_WP_CRON` is set without a server cron job behind it. `wp revision-retention status` reports the same.
* With WP Crontrol active, the **Schedule** tab lists the plugin's cron events with their next run, and links each one to WP Crontrol, where it can be run straight away or removed.
* Saving the settings no longer pushes the scheduled sweep back. A sweep that was part way carries on a minute later instead of a whole interval later. On a fresh install, the first sweep still waits an interval from activation rather than starting with the first save.
* A sweep that is stopped and started again, or picked up by the schedule, stays one line in the log instead of leaving lines behind that read "In progress". A sweep cut off by `--restart` or by deactivating the plugin reads as stopped straight away.
* `wp revision-retention run --post-type=…` is a sweep of its own: it no longer moves the schedule's place in the full sweep, or reports itself as the last full sweep.
* The sweep status counts sweeps finished with **Run now** and WP-CLI, not only scheduled ones.
* The progress text counts every post checked, so a sweep with a small batch size no longer looks stuck.
* The progress bar moves steadily through a site, also with a policy that keeps no revisions, and on the network it moves within each site rather than only when a whole site is done.
* A connection that drops part way through a sweep no longer reads as a failure; the screen says the batch may still have run and keeps the bar where it was.
* A preview that runs to the end is reported as a success, and one that is stopped no longer says the schedule will finish it.
* A refreshed settings screen: tabs that read as one card with the panel, lighter tables, and a post types table whose headings stay in view while scrolling.
* **Sweep every site now** and **Save as network default** ask for confirmation first, in a dialog of the screen's own rather than the browser's prompt.
* On a network where the plugin is activated per site, the network sweep only touches the sites it is active on.
* The warning that WP-Cron does not run no longer shows on the first visit after a quiet spell, when that visit is what sets cron off.
* An optional Dashboard widget with the sweeps of the last 14 days: totals, a bar per day and a link to the Logs tab. Switch it on and pick the roles that see it under **Advanced**; administrators always do. Network administrators get a Site and a Network tab in it.
* The roles picked for the widget may also open the Logs tab, read only, without seeing any of the settings.
* Fields that depend on a switch that is off can no longer be changed, rather than only being dimmed.
* A scheduled sweep that is already late reads as overdue instead of "due in".
* A month after activation, the Dashboard and the plugin's settings ask administrators once whether they would rate the plugin, with **Maybe later** and **Don't ask again** to put it off or turn it down. Asked again after **Maybe later**, it no longer says "for a month now".
* Counts read correctly in the singular, such as "1 revision" instead of "1 revisions".
* The settings screen loads a minified stylesheet and script, built from the readable sources in `src/` that ship with the plugin. A copy without the build, such as a git checkout, loads those sources instead.

= 1.2.0 =
* The log is on by default. A site that saved its settings with the log switched off keeps it off.
* On multisite, **Sweep every site now** sweeps every site right away, with a progress bar, and logs on each site. Booking the sweep through WP-Cron is now labelled **Schedule a sweep on every site**.

= 1.1.0 =
* An optional log of every sweep, who ran it and what it removed, shown as a timeline on its own tab.
* Log entries are kept for a period you choose, from a week to a year, and older ones are deleted daily.
* On multisite, the network screen shows the logs of every site together.

= 1.0.0 =
* Initial release.
* Keep the newest N revisions per post, and remove what is older than a threshold.
* Per post type overrides for both numbers.
* Switch revision support on for post types that ship without it.
* Background sweep that works in batches and books its own next run.
* WP-CLI commands `status`, `post-types` and `run`, with `--dry-run`.
* Network wide defaults with optional per site overrides on multisite, and a way to promote one site's settings to the network.
