# Review responsibilities and decisions

The original prompt assigns 32 expert responsibilities. They were used as review lenses during implementation. This record does not imply independent human reviewers, a separate security audit, or a measured community research study.

| Responsibility | Concrete decision and evidence |
|---|---|
| Product Manager | Dependency-ordered modules, independent flags, usable AI-disabled defaults; optimized brief and acceptance matrix |
| Product Researcher | Cold-start interests and optional onboarding; no invented user interviews or inferred demographics |
| Community Platform Strategist | Native identity/group/activity ownership; explicit trust workflows rather than opaque global scores |
| Principal Software Architect | Composition root with injected services, one policy boundary, versioned provider contracts |
| Senior WordPress Plugin Architect | Activation hooks, capabilities, REST, nonce fallback forms, privacy hooks, dynamic blocks |
| Senior BuddyPress Extension Engineer | Installed upstream symbols verified, native query/comment/message adapters, root capabilities |
| Senior PHP Engineer | PHP 8.1 language floor, typed classes, bounded exceptions, static analysis without a baseline |
| Database Architect | 19 indexed InnoDB tables, unique invariants, prepared values, nested transactions and row locks |
| Data Engineer | Sanitized idempotent events, durable jobs, bounded retention and consent-filtered aggregates |
| Recommendation Systems Engineer | Explicit interests/skills and visible contribution evidence; no sensitive inference |
| Feed/Ranking Engineer | Normalized deterministic signals, following candidates, feedback, diversification, viewer-bound snapshots |
| Search Engineer | Canonical keyword/phrase retrieval, filters, bounded windows and autocomplete; provider hydration through policy |
| Applied AI/ML Engineer | Disabled capability provider, dual consent, minimized public-only gateway, failure backoff; no fabricated model quality |
| Application Security Engineer | Nine root-scoped caps; nonce/cookie boundary; input allowlists; IDOR/SSRF/XSS/SQLi tests; pinned HTTPS |
| Privacy Engineer | Native and custom privacy composition, bilateral blocks, export/erase, generation invalidation, no messages collection |
| Trust & Safety Architect | Consolidated reports, bounded evidence, moderator-only notes, audited actions, subject appeals and credit reversal |
| Senior Product Designer | Coherent community tools navigation and card/form/table vocabulary, useful empty/unavailable states |
| Senior UI/UX Designer | Optional onboarding, visible feedback, consent controls and clear recovery; progressive fallback forms |
| Accessibility Specialist | Native controls, names/labels/status regions, keyboard paths, reduced motion, axe AA browser checks |
| Senior WordPress Frontend Engineer | Scoped WordPress assets/i18n, server rendering, BuddyPress member tabs and Gutenberg previews |
| React/TypeScript Engineer | Existing WordPress editor React APIs used through provided packages; member forms use small JavaScript enhancement. No separate framework bundle was justified by these server-rendered workflows |
| Design-System Engineer | Logical CSS tokens and consistent controls/cards/notices, responsive and dark/reduced-motion styles |
| WordPress Performance Engineer | Bounded candidates, bulk hydration, verified indexed friendship adapter, query measurements and EXPLAIN evidence |
| Product Analytics Engineer | Explicit DAU/WAU/MAU, cohort and health definitions, small-population suppression, descriptive experiment results |
| QA Architect | Acceptance traceability and isolated real WordPress/BuddyPress databases, component and network tests |
| Automated Testing Engineer | PHPUnit, Node tests, Playwright, axe, standards, static and syntax checks with regression repairs |
| CI/CD Engineer | PHP/WordPress matrix, isolated MariaDB, reproducible allowlisted ZIP and artifact reports; no unsolicited deployment |
| WordPress Compatibility Engineer | WordPress 6.8 and 7.1 local runs, BuddyPress 14.5.2, real Multisite and disabled components |
| Internationalization/RTL Engineer | One text domain, POT extraction, wp.i18n, logical CSS, 390px RTL browser verification |
| Technical Writer | Administrator/developer/API/database/privacy/testing manuals and exact release evidence |
| WordPress.org Plugin Review Specialist | GPL-compatible source, dependency declarations, no native patches, opt-in external service, complete package. Directory review remains external |
| Prompt Engineer | Duplicate prose condensed into contracts, dependency order and verification gates without waiving original scope |

## Conflicts resolved

Security and privacy precede ranking richness: provider IDs are rehydrated and checked rather than trusting suggested content. Privacy precedes pagination fullness: revoked objects are removed even if a page has fewer items. Authorization precedes administrative convenience: child-site roles do not imply community-root powers. Data integrity precedes engagement rewards: self-credit and duplicate acceptance are rejected, pair/day limits are locked, reversals append negative ledger records. Functional correctness precedes apparent recall: a saturated answer scan omits uncertain unanswered results.

Compatibility precedes invasive enforcement: upstream source is untouched; unsupported friendship write interception is documented rather than fabricating a hook. Accessibility precedes a client-only application: forms work without JavaScript and browser checks exercise native controls. Performance precedes unlimited discovery: candidate windows and background batches are bounded, with explicit sample-based analytics/topic descriptions. Maintainability precedes a second frontend stack: WordPress's own editor packages and small progressive enhancement share the same REST/domain boundary.

## Review passes

Architecture, upstream compatibility, security, privacy, performance, UI/UX, accessibility, code quality, automated tests, and packaging were revisited after implementation. Repairs include root-capability isolation, nested-comment privacy, native restricted writes, DNS pinning, case evidence/appeal locking, reputation reversal and credit-limit locking, following-candidate retrieval, cached negative feedback, answer pagination, disabled-topic handling, bounded rule capacity, analytics row multiplication, and persistent-cache erasure invalidation. Exact executable evidence and external acceptance limits are in [testing.md](testing.md) and [engineering-report.md](engineering-report.md).
