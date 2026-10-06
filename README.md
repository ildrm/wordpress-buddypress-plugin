# BuddyPress Intelligence

A modular WordPress plugin for BuddyPress community discovery, knowledge, and operations. It runs locally without an AI service or a separate application server.

Members can follow people, groups, topics and public posts; mute, snooze or block; choose interests and privacy preferences; use five feed modes and unified search; ask questions, accept answers, and find people with declared skills and community evidence. Administrators manage topics, reports and appeals, typed automations, consent-based metrics, experiments, and optional intelligence providers.

## Install

1. Use PHP 8.1+, WordPress 6.8+, BuddyPress 14.5.2+, and an InnoDB database with JSON functions. The executed compatibility matrix is in [the engineering report](docs/engineering-report.md).
2. Upload `dist/buddypress-intelligence-1.0.0.zip` through **Plugins → Add New → Upload Plugin**, or copy the plugin directory into `wp-content/plugins`.
3. Activate BuddyPress, then BuddyPress Intelligence. Activation creates 19 plugin tables and grants separate community capabilities to the BuddyPress root site's administrator role.
4. Add `[bpi_community]` to a page, or insert the supplied dynamic blocks. Members also have a **Community tools** profile tab.
5. Open **Community Intelligence → Settings and privacy**. Create topics, review defaults, and configure operational cron.

Production activation needs no Composer or npm install. Development dependencies and isolated test data are excluded from the ZIP. Nothing changes WordPress, BuddyPress, or theme source.

## Defaults and boundaries

AI and analytics consent are off. AI requests require both site permission and member consent, and may send only limited public editorial post text. Private messages are never collected for intelligence. Reputation records community acceptance, not professional certification. Privacy checks run before serialization and again when a cached page is retrieved.

Multisite data belongs to the BuddyPress root site; child administrators do not inherit authority over root community data. Public post discovery is restricted to the root site's selected post types. Native BuddyPress integration supports the verified hooks described in [the developer guide](docs/developer.md).

Uninstall preserves data by default. Permanent deletion requires the root administrator to enable **Permanently delete plugin data on uninstall** before uninstalling. Back up the database first.

## Documentation

- [Administrator and member guide](docs/admin.md)
- [Architecture, developer APIs and integration boundaries](docs/developer.md)
- [REST API reference](docs/rest-api.md)
- [Database and migration reference](docs/database.md)
- [Privacy and data lifecycle](docs/privacy.md)
- [Verification and performance procedures](docs/testing.md)
- [Expert-role review and decisions](docs/role-review.md)
- [Optimized implementation prompt](docs/optimized-prompt.md)
- [Engineering report and exact release verification results](docs/engineering-report.md)
- [Release acceptance and deployment procedure](docs/release-acceptance.md)

Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).
