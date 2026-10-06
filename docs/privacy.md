# Privacy behavior

All retrieval passes through the same object policy: upstream visibility, author preferences, group membership, bilateral blocking, native spam/deletion state, and moderation state. Answers and captured knowledge inherit the original question's visibility. Public posts must be published, unpassworded, in a selected viewable post type, and belong to the BuddyPress root site. Member results contain public identity fields; the plugin does not copy xProfile field data into a second profile database.

Author scopes are Everyone, members, friends, followers, following, selected groups, selected member types, and Only me. Invalid or unavailable conditions deny visibility. These controls govern plugin surfaces and the documented BuddyPress adapters; a published WordPress editorial post keeps its native public URL/status. Administrators can handle authorized moderation cases; this does not authorize ordinary users to read moderator evidence or notes. Root BuddyPress capabilities determine community administration on Multisite, so a child site's administrator cannot obtain root privileges merely from their local role.

Blocking is bilateral for retrieval and supported interaction adapters. Muting and timed snoozing affect recommendations rather than direct keyword lookup. They do not delete the underlying relationship or native content. Restrictions stop plugin interactions and supported native activity/message writes. The public BuddyPress friendship write API has no verified abort filter; the limitation and extension contract are documented in [developer.md](developer.md). Existing friendships are not rewritten by blocking.

## Collection and consent

Plugin records include explicit graph choices, topic mappings/preferences, questions/answers, helpful votes, ranking feedback, an accepted-answer credit ledger, moderation submissions/actions/appeals, and validated automation state. Events store allowlisted identifiers and small scalar metadata. Passwords, access tokens, raw request bodies, IP addresses, messages, and private message text are excluded. No sensitive demographic, health, political, or psychological attributes are inferred.

Site analytics consent and each member's analytics preference are both required to mark events for analytics. Operational events needed by enabled automations may still be stored with analytics false. Views and click/search observations require analytics consent. Aggregates exclude nonconsenting members and withhold populations below five. Experiments are descriptive infrastructure with explicit server-side assignments; there is no automatic enrollment or modification of privacy rules.

AI starts disabled. Sending any data requires an enabled AI module, site transfer consent, requesting member transfer consent, and a configured provider. The built-in gateway permits only publicly visible WordPress editorial post text, strips markup/shortcodes, redacts email addresses, and limits text to 4,000 characters. Member profiles, activity, questions, answers, group discussions, and messages are not exported. No analytics or external telemetry service is bundled.

Provider URLs require public HTTPS on port 443, public resolved addresses, and no URL credentials. Direct cURL transport pins the validated address; redirects are disabled, requests time out after five seconds, and response size is capped at 64 KB. Tokens live in the `BPI_PROVIDER_TOKEN` configuration constant, not the database or responses. Response data is sanitized. The site operator must disclose the selected provider, processing location, terms, and retention separately. Custom PHP extensions are trusted server code and must preserve these contracts.

## Caches and access changes

Community pages and authenticated plugin REST responses send private/no-store cache headers. Site/CDN configuration must honor them. Five-minute discovery snapshots contain only canonical identifiers and safe reason labels. Keys/context bind the root site, viewer, query, filters, page size, result window, and privacy cache generation. Every page hydrates current objects and checks current policy again; a cached result cannot authorize an object.

Erasure rotates the cache generation and removes SQL snapshots. Old snapshots in a persistent cache become unusable immediately and their identifier-only storage expires within five minutes; other applications' cache groups are not flushed. Normal privacy, group, blocking, and negative-feedback changes are enforced when the next page is read. Preference/ranking changes take full effect on a new snapshot.

## Export, erasure, and uninstall

The standard WordPress privacy exporter includes the member's plugin datasets/preferences in pages of 50 per dataset. The eraser removes personal graph edges, mappings, events, feedback, votes, credits, reports, appeals, and assignments; removes incoming member relations; anonymizes authored discussion titles/bodies/authorship; clears subject evidence; and removes identifying assignments, reviewer IDs, peer IDs, and the member's audit notes. It retains discussion structure and anonymized case history to preserve integrity, and explains retained data in the erasure response. Anonymous aggregate totals contain no actor identifiers.

User deletion processes bounded batches and schedules continuation for remaining records. WordPress/BuddyPress remain responsible for erasing their own native data. Text contributed by other members or moderators can mention a person; automated erasure cannot reliably identify every such reference. Site administrators should review relevant retained case notes and native content when handling a request, applying their actual legal and retention obligations. The plugin provides no claim of legal certification.

Deactivation stops scheduled work and preserves records. Uninstall also preserves records unless the root administrator explicitly selects deletion beforehand. Backups and external provider copies are outside local erasure; include them in the site's disclosed retention process.

## Suggested policy text

Our community uses BuddyPress Intelligence to store following and blocking choices, topic preferences, questions and answers, helpful votes, recommendation feedback, and moderation records. When both community and member analytics consent are enabled, eligible events contribute to aggregate statistics with small populations withheld. Optional AI transfer is disabled by default and requires explicit consent; if enabled, only minimized public editorial content is sent to our disclosed provider. Members can manage preferences and use WordPress privacy export and erasure processes. Some anonymized discussion and moderation structure is retained for community integrity.

Adapt this text to the features, providers, backups, retention, and disclosure obligations actually used on the deployment.
