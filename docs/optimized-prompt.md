# BuddyPress Intelligence — executable implementation brief

Build a GPL-compatible modular WordPress plugin in this repository. Preserve established identity; otherwise use BuddyPress Intelligence, `buddypress-intelligence`, namespace `BuddyPressIntelligence`, REST `buddypress-intelligence/v1`, and table suffix `bpi_`. Never alter WordPress, BuddyPress, themes, or other plugins.

## Decision rules

Evaluate product, architecture, WordPress/BuddyPress, PHP/database, data/recommendation/search/AI, security/privacy/trust, design/frontend/accessibility/RTL, performance/analytics, QA/release, documentation, and plugin-review implications at each boundary. Resolve conflicts in this order: security; privacy/authorization; integrity; correctness; compatibility; accessibility; performance; maintainability; usability; extensibility; richness. These are review responsibilities, not permission to invent APIs or claim unexecuted checks.

## Discovery gate

Inspect repository, runtime, dependencies, database scope, tests, and installed upstream source. Record evidence, API signatures, compatibility assumptions, and a requirement gap matrix. Execute existing checks where available. Verify every upstream integration before using it.

## Shared foundation

Implement versioned migrations, granular capabilities, validated settings, independent feature flags, centralized deny-by-default object policy, normalized sanitized events, bounded durable background work, retention, observability, and privacy export/erase. Use WordPress users, BuddyPress groups/activity, and supported post types as canonical objects. Never ingest messages or sensitive inferred attributes. All queries, caches, exports, APIs, and rendered surfaces must preserve upstream visibility and bilateral blocking.

## Dependency order and acceptance

1. Foundation and tests: activation/deactivation/uninstall, schema, settings, permissions, missing dependency handling, multisite scope.
2. Policy/events: visibility scopes, typed events, idempotency, queue retries, retention.
3. Graph/topics: asymmetric follows, mutes, timed snoozes, blocks, aliases, interests, voluntary onboarding, explicit skills and bounded relationship strength.
4. Feed: bounded candidates → native/custom authorization → normalized signals → configurable deterministic score → feedback penalties → diversity → stable pagination → safe explanations. Modes: Latest, Following, For You, Discover, Trending.
5. Discovery/search: members, groups, activity, topics, experts, questions, knowledge, selected public post types; keyword/phrase filtering, autocomplete, pagination; provider boundary and deterministic fallback.
6. Expertise/reputation/Q&A: distinguish declarations from evidence; auditable topic ledger, unique votes, accepted answers, private-group inheritance, reviewed knowledge with provenance.
7. Trust: rate-limited reports, consolidated cases, assignment/priority/severity/notes/evidence, audited reversible supported actions, controlled appeals.
8. Automation: typed allowlisted triggers/conditions/actions, dry run, once-per-rule/event ledger, bounded retries, no arbitrary code/SQL, recursion prevention.
9. Analytics/experiments: opted-in aggregates, explicit metric definitions, suppression of small populations, deterministic bounded assignments; never experiment on policy.
10. Optional intelligence: capability interfaces and disabled provider; explicit site/member consent and public-only minimized payloads, safe endpoints, timeout/response bounds, failure fallback. No unsolicited external calls.
11. Delivery: scoped progressive enhancement, server rendering, coherent admin/member flows, dynamic blocks, responsive logical CSS, translations, meaningful UI states, documentation and reproducible release packaging.

## Per-module gate

Inspect → specify contracts/data/permission/error/UI/test behavior → implement → execute applicable tests → review security/privacy/performance/upstream/accessibility → repair. A class or button alone does not satisfy a feature.

## Verification gate

Test graph uniqueness; deterministic/diverse/paginated feeds; privacy and blocking across every retrieval surface; recommendations/search filters; reputation duplicate prevention and reversal; Q&A ownership/private groups; moderation capability/case/audit/appeals; automation retries/idempotency/recursion; retention/export/erase; every REST permission boundary; XSS/CSRF/SQLi/IDOR/SSRF/mass assignment and race-sensitive writes. Run PHP/unit/integration/static and JavaScript/browser/accessibility checks where executable. Measure bounded paths and report limits honestly.

Exercise original scenarios A–T: cold start, personalization, feedback, blocking, private-group secrecy, diversity, accepted-answer reputation, protected moderation, once-only automation, AI failure/disabled, erasure, no persistent cache, multisite, keyboard/RTL, disabled components, tampered IDs, cache identity isolation.

## Release gate

Ship code, optimized specification, traceable coverage, admin/developer/privacy docs, compatibility matrix, migration notes, changelog, and package. Report exact executed results and distinguish implementation, verification, and external acceptance still required. No placeholders, fabricated evidence, or unsupported production certification.

The original 96-section brief remains the authoritative scope; this brief removes duplication without waiving any requirement.
