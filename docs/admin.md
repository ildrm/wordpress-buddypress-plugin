# Administrator and member guide

## Initial configuration

Activation grants nine independent `bpi_*` capabilities to the root administrator role. Grant a moderator both `bpi_view_cases` and `bpi_moderate`; do not grant settings or AI administration merely to review reports. See the REST capability table for other delegations.

Create a page containing `[bpi_community]`. The optional `view` attribute accepts a member section such as `preferences`, `questions`, `people`, or `search`. Eight dynamic blocks provide feed, people, groups, topics, questions, experts, knowledge and reputation surfaces. All personalized pages must be excluded from CDN/full-page caches; the plugin sets no-cache headers and `DONOTCACHEPAGE` on its page, block and profile-tab surfaces. Check your cache product honors these signals.

Create a small set of useful topics in **Topics**. Use **Edit topic** to rename, adjust aliases/parents, archive or reactivate a topic; paginated administration includes archived topics. Parent topics form a bounded acyclic hierarchy. Members can voluntarily choose interests, declared skills, help topics, goals and groups. Skipping onboarding is supported. Nobody is classified into sensitive inferred categories.

## Member workflows

**Feed** offers Latest, Following, For You, Discover and Trending. Cards explain their recommendation. “More”, “Less” and “Not interested” affect future recommendations; explicit search can still find an otherwise visible item. Follow, mute, snooze and block controls appear on appropriate cards and BuddyPress headers. **Following, muted and blocked** manages existing relationships, including removal after the target's privacy changes.

**Interests and privacy** includes Everyone, signed-in members, friends, followers, people followed, selected groups, selected member types and Only me. These settings tighten community visibility and never grant access through a stricter BuddyPress restriction. Group directory information follows native BuddyPress semantics. Muting and snoozing affect feeds/discovery; blocking is bilateral and also prevents supported native interactions. Private messages remain outside analytics and AI.

**Search** covers members, groups, activity, questions, knowledge, configured public posts and topics. Quoted phrases are supported. Type, topic, author, UTC date and unanswered filters narrow results. Next pages use a five-minute cursor; **Explore more results** moves into another bounded source window. Expired cursors require refreshing the view.

**Questions** supports topics and an optional group context requiring membership. Other eligible members can answer and vote helpful. The question author accepts an answer. Acceptance is idempotent and grants topic evidence to mature accounts within daily and pair limits; self-acceptance awards no points. An administrator with reputation authority can reverse acceptance and its credit. A moderator can publish reviewed knowledge from the accepted answer; it retains group scope and source provenance. **Experts** distinguishes declared skills from evidence; neither is a verified credential.

**Reports and appeals** shows only a member's own report receipts and appeal decisions. Reporting submits a bounded evidence snapshot to authorized reviewers. Report numbers and decisions do not expose internal notes. Only the case subject may appeal an action, and only once per case. Upholding an appeal reopens review; restoring content or removing a restriction is a separate audited decision.

## Moderation

Review consolidated cases with reports, evidence, subject history and an audit trail. Assign an authorized moderator, set severity/priority from 1–5 and record internal notes. Workflow transitions are validated. Supported actions are note, warning, hide/restore, restrict/unrestrict, dismiss and transition. Restriction duration is 1–90 days. Native spam status and bans are never cleared by the plugin's restore action.

A warning records an action and displays a generic member notice. It does not publish the internal note. Hiding is a reversible plugin visibility marker; it does not delete canonical BuddyPress content. A restricted member cannot use plugin interactions or create native activity/comments; optional native messaging is checked before insertion. Existing messages are preserved. Other plugins' custom interaction endpoints remain responsible for calling the published policy.

## Automations

Create an allowlisted trigger, optional group/topic/member-type/minimum-reputation conditions, and one action: notify, internal state, feature activity or moderation review. Internal state requires a JSON object such as `{"state":"welcomed","value":"yes"}`. Start in dry-run mode and inspect run history before enabling actions. No rule can execute PHP, shell commands, SQL or evaluated templates.

Use **Edit automation** to enable or disable a saved rule, change typed conditions/action options, and switch between dry run and live operation. Administration is paginated and capacity is 100 rules. Run identity is `(rule,event)`. Completed runs cannot execute again. Failed jobs use bounded retry/backoff and appear in diagnostics; retry only after repairing the cause. BuddyPress notifications are used when enabled, otherwise the member sees a local notice. Feature markers influence feed quality; they never bypass eligibility. Automation-generated events do not trigger rules again.

## Analytics and experiments

Analytics needs both administrator collection consent and each member's consent. Definitions are displayed alongside DAU/WAU/MAU, event counts and separate concentration, unanswered-content and weekly-return indicators. A missing/suppressed value is **Withheld**, not zero. These are descriptive indicators, not a universal health score. Event retention bounds the measurable history.

Experiments have 2–4 named variants, one allowed response metric and a window of at most 90 days. Drafts can start; running definitions are immutable and can end. Assignment is deterministic and persisted. Developers explicitly request assignments and attach the experiment ID to eligible metric events. Creating an experiment alone does not change a ranking policy or enroll users silently. Results report consenting observed-member response rates, suppress small cells and make no statistical significance claim. Policy and security must remain invariant across variants.

## Settings, diagnostics and maintenance

Each module can be disabled independently. Candidate limits are 1–300. Ranking weights are nonnegative and normalized. Raw events default to 90 days and aggregates to 365; settings allow at most 730. Diagnostics report runtime versions, schema, job states and the latest provider failure without credentials.

Use an operational cron runner on the root site. With WP-CLI installed, run `wp cron event run --due-now --url=<root-site-url>` periodically. WP-Cron otherwise relies on visits. Monitor failed jobs and delayed maintenance; cleanup deletes bounded batches per invocation, so a backlog may need additional scheduled runs.

AI is optional and off by default. Configure a public HTTPS endpoint, store a credential only as `BPI_PROVIDER_TOKEN` in `wp-config.php`, and supply a privacy disclosure naming the provider and its retention terms. Both consent checks still apply. The built-in transport sends public editorial text only, limits payload/response sizes, pins a validated public address, disables redirects and backs off after failures. Never place credentials in forms, rule JSON or translated content.

## Removal and recovery

Use WordPress **Tools → Export Personal Data / Erase Personal Data** for member requests. Discussions are anonymized to preserve references; eligible personal edges/events/feedback/votes/reputation/reports/appeals/assignments are removed. Anonymous aggregates are retained until retention cleanup.

Deactivation stops plugin cron and preserves data. Uninstall also preserves data unless permanent deletion was explicitly enabled. Make and verify a backup before that setting. There is no destructive reset button. For rollback after an upgrade, restore a coherent code/database backup; downgrades must not reinterpret a newer schema.
