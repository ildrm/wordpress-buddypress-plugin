# Release acceptance and deployment

BuddyPress Intelligence 1.0.0 is an installable release with locally executed quality evidence. The optimized specification, all 32 responsibility reviews, implementation contracts and acceptance mapping are included in the documentation. Exact results are in [engineering-report.md](engineering-report.md).

## Completed software gates

- Strict PHPUnit integration on PHP 8.1/8.2/8.3 with WordPress 6.8, 7.1 and 7.1.2; real BuddyPress 14.5.2 and MariaDB.
- Full integration with real Redis, fresh-process privacy invalidation, and real Multisite root/child isolation.
- Competing acceptance, voting, automation, capacity, queue and rate-limit operations.
- Browser/member/moderator/administrator workflows, no-JavaScript forms, keyboard, mobile RTL, dark mode and scoped axe AA checks on WordPress 7.1.2.
- Syntax, WordPress coding standards, PHPStan without a baseline, JavaScript regressions and JavaScript/CSS lint.
- Translation generation, reproducible allowlisted ZIP, every-file hash verification, and actual extracted-package activation/deactivation/preservation/opted-in uninstall lifecycle.

The release contains no production Composer/npm dependency tree, test database, provider credential or QA cache package. Uninstall preserves data by default. This release uses initial schema 1; source/database backups must be restored coherently when rolling back.

## Install on the chosen host

1. Back up the target site's database and files, and verify restoration on staging. Use maintained upstream WordPress/BuddyPress/PHP patches that satisfy the declared minimums.
2. Upload the release ZIP through WordPress Plugins, with BuddyPress already active. Activate on the BuddyPress root site and check Community Tools diagnostics for schema 1, expected runtime versions and scheduled maintenance.
3. Configure only the modules and ranking settings needed by the community. AI and behavioral collection stay disabled until the administrator configures the service/consent/disclosure and members opt in.
4. Exclude personalized community pages and authenticated REST responses from shared page/CDN caches. Configure an operational root-site cron runner and monitor delayed or failed jobs.
5. Exercise the actual theme/plugin stack on staging: anonymous access, two separate members, private/hidden groups, both block directions, native messages/friendship requests, question acceptance/reversal, moderation/appeal, export/erasure and any root/child configuration. The supplied suites demonstrate the supported contracts; custom interaction writers must use `Policy`.
6. Deploy the verified staging artifact with the normal host change process, then monitor diagnostics and queue health. Keep the previous coherent backup available until acceptance is complete.

No target installation, hosting account, selected theme stack or real intelligence provider was supplied for this task. Therefore production activation, host backup restoration, provider-account operation, remote CI and host capacity are external deployment checks. They are not unresolved defects in the verified local release, and they are not represented as completed operations.

## Scope of evidence

The population benchmark measures bounded feed requests against synthetic user counts. Focused worker races demonstrate tested invariants with healthy workers; they do not establish peak throughput or exactly-once delivery under every crash. AI and search providers use explicit extension contracts with a working disabled/local fallback; no external vector index or provider account is bundled. Automated accessibility checks cover the plugin surfaces in the tested browser; other themes, assistive technology and translations require their own acceptance.
