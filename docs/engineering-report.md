# Engineering report — BuddyPress Intelligence 1.0.0

The original brief was optimized into [an executable specification](optimized-prompt.md) before implementation. The baseline repository contained a README and GPL license. The resulting plugin is implemented, verified locally, and packaged. This report records executed checks separately from external deployment acceptance; it does not claim independent certification.

## 1. Implemented modules

Social follows/mutes/snoozes/bilateral blocks; hierarchical topics/aliases/interests and voluntary onboarding; sanitized events and durable jobs; five feed modes, feedback and diversity; member/group/content/topic/expert discovery; unified keyword/phrase search with filters/windows/autocomplete and provider extension; declared expertise and visible topic evidence; accepted-answer reputation/reversal; questions/answers/helpful votes/reviewed knowledge; reports/cases/evidence/audit/restrictions/appeals; typed automations and run history; consent-based analytics/health; experiment definitions/assignments/results; optional AI capability gateway; administration/member preferences; REST/developer APIs; dynamic blocks; BuddyPress surfaces; Multisite/i18n/RTL; export/erasure/retention; diagnostics and migration/package tooling.

## 2. Architecture

24 PHP service/interface classes with injected dependencies, one composition root, one canonical-source adapter, one shared deny-by-default policy, a prepared persistence boundary, and versioned provider contracts. PHP server rendering and progressive JavaScript use the same write/authorization contracts. Admin metadata readers have explicit root capability checks. WordPress's editor packages supply block previews; no separate application server or production dependency bundle is required. See [developer.md](developer.md) and [role-review.md](role-review.md).

## 3. Storage and migrations

19 root-prefixed InnoDB tables; unique graph/event/vote/ledger/case/run/job/assignment invariants; indexed actor/time and queue paths; row locks and nested savepoints. Schema 1 is the initial release. Repeat initialization and refusal to downgrade are supported. See [database.md](database.md).

## 4. REST API

35 route patterns under `buddypress-intelligence/v1`, with allowlisted methods, typed bounded input, nine granular root capabilities, current-object policy checks, stable sanitized errors, rate limits and private/no-store responses. [rest-api.md](rest-api.md) lists each route, input and permission.

## 5. BuddyPress integration

Native users/groups/activity remain authoritative. Verified query filters include specific activity and nested comments. Member navigation/headers, native activity/comment restrictions, blocked native message recipients, root capabilities, and component fallbacks are implemented. Native friendship REST reads, creation/acceptance, Legacy/Nouveau AJAX and add/accept screens honor policy; cancellation remains available. Removal events use the confirmed post-delete hook, fixing the nullable/pre-delete hook bug. No upstream files are patched. The bounded friendship batch adapter is isolated and documented. Direct PHP and third-party writers use the policy contract.

## 6. Security

Authentication plus capabilities/ownership; native WordPress nonces; no trust in supplied actor IDs; input/table/column allowlists; prepared values; escaped text/URLs and `wp_kses_post`; unique/locked integrity operations; bounded jobs and abuse windows; HTTPS endpoint validation, address pinning, no redirects, timeout/response limits; no executable automation templates; no credentials in settings/events/errors. Tests cover the boundaries in [testing.md](testing.md).

## 7. Privacy

Most restrictive native/custom authorization applies before serialization and on cached pages. Bilateral blocking, group inheritance, scope controls, dual analytics/provider consent, public-only minimized AI, small-population suppression, export/erase and retained-history explanations are implemented. SQL snapshots are removed and persistent snapshot generations invalidated on erasure. Old identifier-only cache storage expires within five minutes. See [privacy.md](privacy.md).

## 8. Ranking and recommendations

Bounded canonical candidates plus followed-member candidates; eligibility; six normalized weighted signals; deterministic tie-breaks; feedback; bounded author/group/topic diversity; snapshot pagination; safe translated reasons. Recency and meaningful interactions decay; trending bounds participant/reply effects; accepted-answer evidence is visible-object/topic checked and decays. Experts require explicit skill/help-topic declarations and use interest/evidence scores. Weights are administrator configurable. Ranking never overrides policy.

## 9. Background work

Hourly maintenance and single-event queue scheduling on the community root. At most 25 claims and a 15-second worker budget; 10-minute stale lease recovery, five attempts and bounded exponential backoff; replacement lease comparisons prevent old workers overwriting new results. Once-only automation runs, bounded retention batches, consent-filtered daily rollups and continued member erasure are included. Production cron operation remains a host responsibility.

## 10. User interfaces

Member tools for feed/discovery/search/topics/questions/knowledge/reputation/preferences/relationships/reports; eight dynamic blocks; BuddyPress navigation/action integrations. Admin settings, diagnostics/retries, paginated topic and automation editing, protected moderation/appeals, metrics and experiment start/end/results. Logical responsive CSS, dark/reduced-motion support, keyboard-native controls, translations and JavaScript-disabled forms are included.

## 11–13. Executed tests and analysis

| Executed check | Exact result |
|---|---|
| PHPUnit 10.5.66; WordPress 7.1.2 / BuddyPress 14.5.2 / Windows PHP 8.2.12 | **53 tests, 631 assertions; passed**, 20.487 seconds |
| Full Linux matrix: PHP 8.1.34 / 8.2.34 / 8.3.35 × WordPress 6.8 / 7.1 / 7.1.2, BuddyPress 14.5.2 | **All nine cells: 53 tests, 631 assertions each; passed**, no failures/errors/skips/notices/deprecations |
| Full PHP 8.3.35 / WordPress 7.1.2 suite with Redis 7.4.11 and Redis Object Cache 2.7.0 | **53 tests, 631 assertions; passed** |
| PHP syntax | **70 files; zero failures** across production and verification sources |
| WordPress coding standards (PHPCS 4.0.4 / WPCS 3.2.0) | **26 production PHP files; zero violations** |
| PHPStan 2.2.17, level 5, PHP 8.1 language target | **No errors**, no baseline or ignored error collection; 2 GB analysis budget |
| Node JavaScript regressions | **5 tests passed** |
| Playwright / Chrome, WordPress 7.1.2, one worker | **8 workflows passed**; repeated after persistence repairs; scoped axe AA checks reported zero violations |
| ESLint / Stylelint | **Passed** |
| Real Multisite | **14 checks passed** |
| Real Multisite with Redis / PHP 8.3.35 / WordPress 7.1.2 | **15 checks passed**, including root/child cache, capabilities and cron |
| Competing processes and cross-process Redis privacy | **15 checks passed**: acceptance, votes, automation, rule capacity, queue, rate ceiling, snapshot/block/erasure |
| Activity/groups/friends/notifications disabled | **4 fresh-process checks passed** |
| Native BuddyPress blocked messaging | **Rejected before persistence; passed** |
| Extracted ZIP lifecycle | **48 checks passed**: activation, 19 tables, defaults/caps/cron, deactivation, preserved and explicitly deleted uninstall, native account retained |

PHP and browser checks were repeated after material repairs. PHPUnit is strict about notices, deprecations, warnings, skipped and risky tests. The browser suite covers eight workflows and scoped axe AA checks on mobile RTL, dark mode, populated question, automation and moderation surfaces. `.runtime/e2e-results.json` records eight expected results, zero unexpected, zero skipped and zero flaky results. Screenshots were inspected. Reports and screenshots are generated under `.runtime`, not distributed as runtime dependencies. The Windows host emits a pre-existing duplicate OpenSSL startup warning; Linux matrix cells do not. A Docker interruption stopped disposable services; incomplete cells were discarded and rerun successfully. Runners require explicit completion evidence and current reports, preventing a zero-exit WordPress error page from being counted as a pass. No remote CI or independent security audit is claimed.

The concurrency harness synchronizes fresh processes before competition. Twelve acceptance requests select one answer, advance revision once, emit one event and award one credit; winning retries are idempotent. Twelve helpful votes persist once. Twelve automation deliveries commit one run and one native notification. Eight creators racing for the final rule slot preserve the 100-rule capacity. Eight workers process 120 handlers once each without stranded leases. Three hundred rate requests stay within a 40-request ceiling (39 were admitted in the first executed race; conservative rejection is permitted). Fresh processes prove real Redis persistence, exclusion after blocking, and cursor invalidation after erasure. These focused healthy-process invariants do not establish global load capacity or exactly-once behavior under every crash.

Repeating the race exposed a genuine deadlock while completing an already successful job. The worker now retries only the conditional autocommit state write, with three bounded attempts for deadlock/lock-timeout codes. Exhaustion retains the lease and does not mislabel the handler as failed or immediately replay it. Real MySQL error injection verifies transient, exhausted and permanent failures; transactional callbacks are rejected. Failed SQL reads now throw before privacy serialization. The corrected race passed, and its regressions are included in the full suite. Built-in handlers remain idempotent for later crash/stale-lease recovery.

## 14. Known boundaries

The final isolated feed benchmark used verified synthetic user counts and three fresh snapshots per stage, with local object cache flushed between samples:

| Synthetic members | Request durations (milliseconds) | SQL queries per request | Returned items |
|---|---|---|---|
| 1,000 | 128.47 / 103.84 / 91.30 | 26 | 20 |
| 10,000 | 86.16 / 90.70 / 92.22 | 26 | 20 |
| 100,000 | 122.05 / 112.13 / 111.39 | 26 | 20 |

The dataset adds 150 public activities per stage. It is not a concurrent-load or 100,000-active-member simulation. No p95/p99 estimate is supported by these nine samples. EXPLAIN checks cover privacy relation, event window and due-job claim indexes; queue ordering follows availability/id to avoid sorting the eligible backlog. See `.runtime/benchmark.json` and [testing.md](testing.md).

Semantic ranking uses an optional `SearchProvider`; no external vector index or model is bundled. AI provider failure/consent/payload behavior is verified with test providers; no real provider account was supplied. Experiments supply explicit assignment and descriptive results; they do not silently enroll members or rewrite feed weights. Topic statistics, reputation evidence and discovery use documented bounded samples/windows. Saturated unanswered scans omit uncertain questions. Native filtered directories can produce shortened pages. Unsupported third-party endpoints and direct PHP interaction writers must use the policy API; supported native friendship HTTP paths are guarded.

Optional Composer installation and extra Windows PHP binaries could not be downloaded successfully. Release verification instead used pinned official QA tools and the completed Linux PHP matrix. A real isolated Redis deployment was exercised, including Multisite and fresh-process privacy. No remote CI, exhaustive theme/plugin conflict matrix, screen-reader certification or host capacity claim is made. The actual deployment target and real provider account were not supplied. See [release-acceptance.md](release-acceptance.md) for completed software gates and host acceptance.

## 15. Compatibility

| Environment | Status |
|---|---|
| PHP 8.2.12 / MariaDB 10.4.32 / BuddyPress 14.5.2 | Executed local integration and browser runtime |
| WordPress 7.1 | Local source runtime and HTTP workflows executed |
| WordPress 6.8 | Official source, separate-prefix integration executed |
| WordPress 7.1.2 | Official source; all three Linux PHP versions, Windows integration and HTTP workflows passed |
| Real WordPress Multisite | Separate `bpin_` root/child/capability/cache/cron checks passed |
| Activity/groups/friends/notifications disabled | Four fresh-process checks passed; unrelated topic discovery remained available |
| PHP 8.1.34 / 8.2.34 / 8.3.35, MariaDB 10.4.34 | Executed official Linux container matrix, all nine WordPress combinations passed |
| Redis 7.4.11 / Redis Object Cache 2.7.0 with Predis | Full integration, fresh processes and real Multisite passed |
| Other BuddyPress versions / production theme stack / hosting account | Require deployment acceptance on the chosen target |

[WordPress's release archive](https://wordpress.org/download/releases/) is the primary download source; the declared minimum and locally executed versions are not a claim that they are the latest security patches. The plugin declares WordPress 6.8+, PHP 8.1+, and requires BuddyPress 14.5.2+ for its verified adapters.

## 16–18. Documentation, upgrade and package

README, WordPress readme, changelog, optimized prompt, initial audit, all 32 role reviews, administrator/developer/REST/database/privacy/testing/release-acceptance guides, and a translation template are included. Before upgrading, back up and restore code/database coherently; schema 1 has no preceding released migration. Uninstall preserves data unless explicitly configured for deletion.

`dist/buddypress-intelligence-1.0.0.zip` contains 45 production/documentation files. An explicit allowlist excludes tooling, test databases, dependency trees and credentials. Fixed timestamps make builds reproducible; every entry is verified against a SHA-256 manifest. `dist/manifest.json` records the archive hash outside the archive to avoid a self-referential hash. Actual WordPress lifecycle checks exercised the extracted artifact, including cleanup confined to `bpit_release_`. Deployment/publishing is not performed by this local implementation task.

## Original acceptance scenarios

| Scenario | Implementation/evidence |
|---|---|
| A — Cold start | Explicit interests/onboarding and topic/member/group recommendations without behavioral history; discovery fixture |
| B — Personalized vs Latest | Followed content outside latest window included; distinct feed regression |
| C — Negative feedback | New and cached feeds exclude Not interested; explicit search remains eligible |
| D — Blocking | Bilateral graph/policy, discovery/native comments/message tests |
| E — Group secrecy | Private/hidden policy, all retrieval channels, native REST and group Q&A inheritance |
| F — Topics/diversity | Topic mappings/preferences, normalized signals and deterministic diversity unit tests |
| G — Q&A/expertise | Declared skills, accepted answers, credit/reversal integration and browser workflow |
| H — Reporting | Consolidated cases, audit/appeals/capability tests and reporter/moderator UI workflow |
| I — Automation | Unique run ledger, dry run, duplicate/recursion and bounded rule tests |
| J/K — AI failure/disabled | Mock failure/backoff, disabled provider, core retrieval available |
| L — Erasure | Own datasets, discussion anonymization, paginated batches and cache generation tests |
| M — No persistent cache | Default local runtime uses ordinary WordPress caching/transients |
| N — Multisite | Real root/child data, options, caches, authority and cron isolation checks |
| O/P — Keyboard/RTL | Keyboard member/case controls, no-JavaScript fallback, mobile RTL and scoped axe checks |
| Q/R — Disabled components | Fresh-process component checks and unrelated topic functionality |
| S — Tampered identifiers | Ownership, private group, mass assignment, all-route permission regressions |
| T — Cache viewer isolation | Context-bound cursors reject other users and recheck current visibility |

This matrix identifies equivalent exercised behavior, not a claim of exhaustive property proof for every possible community configuration.
