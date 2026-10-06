# Initial audit

Repository baseline: one initial commit containing README.md and GPL-2.0 LICENSE; no bootstrap, dependencies, tests, schema, hooks, routes, or assets. No product identity beyond repository name. Existing tests: none to execute. Identity chosen under original section 2: BuddyPress Intelligence.

Host observed: Windows PowerShell; PHP 8.2.12; Composer 2.8.10; Node available. PHP emits a pre-existing duplicate OpenSSL module warning. Docker engine is inaccessible in the sandbox. Local WordPress source reports 7.1; no BuddyPress plugin found in either local installation. No existing site configuration or database is modified. Optional active components, theme, and multisite are deployment facts, not assumed host facts.

Data decision: plugin-owned data is scoped to BuddyPress root blog; WordPress content outside that blog is deliberately excluded. The root site's `$wpdb->get_blog_prefix()` determines tables. This avoids cross-site post-ID ambiguity. Capabilities and settings live on that root blog. Network activation initializes root only, not a table copy for every site.

Architecture decision: composition root with small application services, a prepared-SQL repository, one shared policy, and one native-object adapter. Server-rendered PHP and small native JavaScript suit WordPress progressive enhancement; React/TypeScript are not required for non-SPA controls. Security and low hosting requirements take precedence over unnecessary dependencies.

## Initial gap matrix

| Requirement family | Existing | Planned component |
|---|---|---|
| Identity, licensing | GPL license | Bootstrap, readme, packaging |
| Settings, migration, flags, diagnostics | Missing | Core, Database |
| Native visibility, scopes, component fallbacks | Missing | NativeObjects, Policy |
| Events, retention, queue | Missing | Events, Jobs |
| Social/interest/topic graph, onboarding | Missing | Graph, Topics |
| Feed, ranking, feedback, discovery | Missing | Ranking, Discovery |
| Unified search and provider contracts | Missing | Search, Providers |
| Expertise and topic reputation | Missing | Knowledge, ledger |
| Q&A, votes, acceptance, knowledge | Missing | Knowledge |
| Reports, cases, actions, appeals | Missing | Moderation |
| Typed automation, retries, run history | Missing | Automation |
| Analytics, health, experiments | Missing | Analytics |
| Optional AI and consent | Missing | Intelligence |
| REST, blocks, BuddyPress, admin/member UI | Missing | REST, Integration, UI |
| Multisite, i18n/RTL, privacy tools | Missing | Core, Privacy, scoped assets |
| Automated verification and release | Missing | tests, CI, docs, packaging |

Compatibility declarations are a target until execution confirms them. See verification report for observed versions and remaining release gates.
