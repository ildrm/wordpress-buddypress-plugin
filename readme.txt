=== BuddyPress Intelligence ===
Requires at least: 6.8
Tested up to: 7.1.2
Requires PHP: 8.1
Requires Plugins: buddypress
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: buddypress, community, discovery, moderation, questions

Privacy-aware feeds, discovery, questions, reputation, moderation, and community operations for BuddyPress.

== Description ==

BuddyPress Intelligence adds local, deterministic community discovery and administrative workflows. Features include follows, interests, topics, five feed modes, member/group/expert discovery, unified search, questions and reviewed knowledge, topic reputation, reports and appeals, typed automations, opt-in analytics, and experiment infrastructure.

The plugin requires BuddyPress 14.5.2 or later. Optional BuddyPress components degrade gracefully. It does not modify core or theme files. It requires InnoDB and JSON database functions; MariaDB 10.4 was used in the executed tests.

Optional external intelligence is disabled by default. Enabling it requires administrator configuration and member consent. A configured HTTPS endpoint receives up to 4,000 characters of public editorial post text, with email patterns redacted. Member, activity, question and message content is excluded. The administrator must disclose the chosen provider's terms and retention policy. The HTTP adapter requires direct cURL transport and does not operate through a WordPress HTTP proxy.

Analytics excludes members who have not consented and suppresses small populations. Export and erasure are registered with WordPress privacy tools. Deactivation retains data. Uninstall also retains data unless permanent deletion was explicitly enabled in plugin settings.

== Installation ==

1. Install and activate BuddyPress.
2. Upload this plugin and activate it.
3. Add [bpi_community] to a page or insert the community dynamic blocks.
4. Configure Community Intelligence in the WordPress dashboard.
5. Configure a reliable cron runner for background processing and retention.

== Frequently Asked Questions ==

= Does this require AI? =
No. Feed, search, recommendations and community workflows work locally. The optional provider interface has a disabled implementation.

= Are private groups protected? =
Native group status, membership, bans, bilateral blocks and member privacy are enforced before returning content. A private group's public directory listing may remain visible, as in BuddyPress; its activity and discussions remain restricted.

= Can it be used on Multisite? =
Yes. Community tables, settings, capabilities and cron are scoped to the BuddyPress root. Public post discovery uses that site's posts only.

= Is reputation a verified qualification? =
No. Declared skills and community accepted-answer evidence are distinct. Reputation is topic-specific, bounded and reversible.

== Changelog ==

= 1.0.0 =
Initial implementation. See docs/engineering-report.md for executed checks, compatibility coverage and release limitations.
