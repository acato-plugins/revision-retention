=== Revision Retention ===
Contributors: acato, paulacato
Tags: revisions, database, cleanup, performance, post types
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
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

The sweep runs in the background at an interval you choose. Every run books the next one itself: another batch in a minute while there is work left, or the next full run at your interval once the site is clean. A sweep that gets interrupted continues where it stopped instead of starting over.

There are also **Preview** and **Run one batch now** buttons on the settings screen. Preview reports what the policy would remove without deleting anything.

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

The sweep itself runs per site, under whatever policy that site ends up with.

= For developers =

    // Never touch these posts, whatever the policy says.
    add_filter( 'revision_retention_excluded_posts', fn( $ids ) => [ ...$ids, 42, 108 ] );

    // Decide the rule for a post type in code.
    add_filter( 'revision_retention_rule', function ( $rule, $post_type ) {
        return 'page' === $post_type ? new Retention_Rule( 20, 90 ) : $rule;
    }, 10, 2 );

    // Change which post types can have a policy at all.
    add_filter( 'revision_retention_eligible_post_types', $callback );

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

= Where are the translations? =

Translations are managed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/revision-retention/) and are downloaded by WordPress automatically. The plugin ships a `.pot` template for translators, but no locale files of its own.

== Screenshots ==

1. The settings screen: the policy, and the per post type table with the revisions stored right now.
2. Preview reporting what the policy would remove before anything is deleted.
3. The network settings screen on multisite, with the switch deciding whether sites may override.

== Changelog ==

= 1.0.0 =
* Initial release.
* Keep the newest N revisions per post, and remove what is older than a threshold.
* Per post type overrides for both numbers.
* Switch revision support on for post types that ship without it.
* Background sweep that works in batches and books its own next run.
* WP-CLI commands `status`, `post-types` and `run`, with `--dry-run`.
* Network wide defaults with optional per site overrides on multisite.
