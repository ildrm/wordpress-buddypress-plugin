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

Native users/groups/activity remain authoritative. Verified query filters include specific activity and nested comments. Member navigation/headers, native activity/comment restrictions, blocked native message recipients, current root capabilities, and native component fallbacks are implemented. No upstream files are patched. The read-only bounded friendship batch adapter is isolated and documented. Native friendship writes require cooperating callers to invoke policy; no unsupported abort hook is invented.

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
| PHPUnit 10.5.66; WordPress 7.1 / BuddyPress 14.5.2 / PHP 8.2.12 | **42 tests, 557 assertions; passed**, 18.317 seconds |
| Same suite; official WordPress 6.8 / BuddyPress 14.5.2 / PHP 8.2.12 | **42 tests, 557 assertions; passed**, 15.470 seconds |
| PHP syntax | **58 files; zero failures** |
| WordPress coding standards (PHPCS 4.0.4 / WPCS 3.2.0) | **26 production PHP files; zero violations** |
| PHPStan 2.2.17, level 5, PHP 8.1 language target | **No errors**, no baseline or ignored error collection; 2 GB analysis budget |
| Node JavaScript regressions | **5 tests passed** |
| Playwright / Chrome, one worker | **8 workflows passed**, 1.9 minutes; scoped axe AA checks reported zero violations |
| ESLint / Stylelint | **Passed** |
| Real Multisite | **14 checks passed** |
| Activity/groups/friends/notifications disabled | **4 fresh-process checks passed** |
| Native BuddyPress blocked messaging | **Rejected before persistence; passed** |
| Extracted ZIP lifecycle | **48 checks passed**: activation, 19 tables, defaults/caps/cron, deactivation, preserved and explicitly deleted uninstall, native account retained |

PHP and browser checks were repeated after material repairs. The browser suite covers eight workflows and scoped axe AA checks on mobile RTL, dark mode, populated question, automation and moderation surfaces. `.runtime/e2e-results.json` records eight expected results, zero unexpected, zero skipped and zero flaky results. Screenshots were inspected. Reports and screenshots are generated under `.runtime`, not distributed as runtime dependencies. The host emits a pre-existing duplicate OpenSSL startup warning; this is separate from plugin test warnings. No remote CI or independent security audit is claimed.

## 14. Known boundaries

The final isolated feed benchmark used verified synthetic user counts and three fresh snapshots per stage, with local object cache flushed between samples:

| Synthetic members | Request durations (milliseconds) | SQL queries per request | Returned items |
|---|---|---|---|
| 1,000 | 128.47 / 103.84 / 91.30 | 26 | 20 |
| 10,000 | 86.16 / 90.70 / 92.22 | 26 | 20 |
| 100,000 | 122.05 / 112.13 / 111.39 | 26 | 20 |

The dataset adds 150 public activities per stage. It is not a concurrent-load or 100,000-active-member simulation. No p95/p99 estimate is supported by these nine samples. EXPLAIN checks cover privacy relation, event window and due-job claim indexes; queue ordering follows availability/id to avoid sorting the eligible backlog. See `.runtime/benchmark.json` and [testing.md](testing.md).

Semantic ranking uses an optional `SearchProvider`; no external vector index or model is bundled. AI provider failure/consent/payload behavior is verified with test providers; no real provider account was supplied. Experiments supply explicit assignment and descriptive results; they do not silently enroll members or rewrite feed weights. Topic statistics, reputation evidence and discovery use documented bounded samples/windows. Saturated unanswered scans omit uncertain questions. Native filtered directories can produce shortened pages. Unsupported third-party interaction endpoints must use the policy API. Complete native friendship write interception is unavailable through the verified upstream public API.

Composer installation and extra PHP binaries could not be downloaded successfully. No remote PHP matrix result, Redis deployment, exhaustive theme/plugin conflict matrix, screen-reader certification or high-concurrency capacity claim is made. These environmental acceptance limits do not become fabricated successes.

## 15. Compatibility

| Environment | Status |
|---|---|
| PHP 8.2.12 / MariaDB 10.4.32 / BuddyPress 14.5.2 | Executed local integration and browser runtime |
| WordPress 7.1 | Local source runtime and HTTP workflows executed |
| WordPress 6.8 | Official source, separate-prefix integration executed |
| Real WordPress Multisite | Separate `bpin_` root/child/capability/cache/cron checks passed |
| Activity/groups/friends/notifications disabled | Four fresh-process checks passed; unrelated topic discovery remained available |
| PHP 8.1 and 8.3 | Language floor/static target and CI configured; additional binary download blocked |
| Latest upstream patch releases / other BuddyPress versions / production theme stack | Not represented by the local version tests; run deployment acceptance |

[WordPress's release archive](https://wordpress.org/download/releases/) is the primary download source; the declared minimum and locally executed versions are not a claim that they are the latest security patches. The plugin declares WordPress 6.8+, PHP 8.1+, and requires BuddyPress 14.5.2+ for its verified adapters.

## 16–18. Documentation, upgrade and package

README, WordPress readme, changelog, optimized prompt, initial audit, role review, administrator/developer/REST/database/privacy/testing guides, and a translation template with 244 messages are included. Before upgrading, back up and restore code/database coherently; schema 1 has no preceding released migration. Uninstall preserves data unless explicitly configured for deletion.

`dist/buddypress-intelligence-1.0.0.zip` contains 44 production/documentation files. An explicit allowlist excludes tooling, test databases, dependency trees and credentials. Fixed timestamps make builds reproducible; every entry is verified against a SHA-256 manifest. `dist/manifest.json` records the archive hash outside the archive to avoid a self-referential hash. Actual WordPress lifecycle checks exercised the extracted artifact, including cleanup confined to `bpit_release_`. Deployment/publishing is not performed by this local implementation task.

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
