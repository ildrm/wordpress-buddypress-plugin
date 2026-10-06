# Database and migrations

The plugin uses the BuddyPress root site's prefix followed by `bpi_`, obtained from `$wpdb->get_blog_prefix(bp_get_root_blog_id())`. It never assumes `wp_`. A network shares one community graph and configuration at this root. WordPress users, BuddyPress activity/groups/friendships, and public WordPress posts remain canonical upstream objects.

All 19 plugin tables have an unsigned bigint primary key and UTC `created_at` retention index. New tables use InnoDB and the site's charset/collation. Relationships to upstream objects use typed identifiers rather than foreign keys that could block native deletion or upgrades.

| Table suffix | Purpose | Integrity and retrieval indexes |
|---|---|---|
| edges | Directed follows, mutes, snoozes, blocks | Unique actor/kind/type/object; reverse lookup; expiry |
| topics | Hierarchy, aliases, active/archive state | Unique slug; status/parent |
| object_topics | Explicit topic mappings | Unique type/object/topic; reverse topic/object |
| events | Sanitized typed domain events | Unique event key; pending; actor/time; type/time |
| feedback | Member ranking preferences | Unique actor/type/object |
| ledger | Accepted-answer credits and reversals | Unique event key; actor/topic/time; actor/peer/time; object |
| entries | Questions, answers, reviewed knowledge | Kind/status/id; parent/kind/id; author; group |
| votes | Unique helpful-answer endorsements | Unique actor/object; object |
| cases | Consolidated moderation workload and evidence | Unique type/object; status/update; subject |
| reports | Reporter submissions | Unique case/reporter; actor |
| audit | Moderator actions and previous states | Case/id; actor |
| appeals | Subject appeals and explicit decisions | Unique case/actor |
| rules | Validated automation definitions | Enabled/trigger |
| runs | Once-per-rule/event execution ledger | Unique rule/event; status/id |
| jobs | Durable queue, leases, retries | Unique job key; status/availability/id |
| aggregates | Suppressed daily community metrics | Unique day/metric/dimension |
| experiments | Bounded variant definitions and windows | Primary and retention indexes |
| assignments | Stable member variants | Unique experiment/actor; actor |
| limits | Abuse windows and write mutexes | Unique bucket; expiry |

`Database::COLUMNS` allowlists table names and columns. Data uses prepared placeholders; table identifiers derived from this allowlist are internal. CRUD updates/deletes require a where clause. The generic row reader caps at 500 rows. Domain readers use tighter candidate/page limits, indexed lookups, or bounded batches. These bounds are intentional: discovery does not load the community into PHP memory.

Transactions support nested savepoints. Accepted answers lock their question; credit limits lock an actor mutex; appeals lock their case; automation runs lock the unique execution row. Unique inserts return the existing ID using `LAST_INSERT_ID(id)` and do not hide unrelated write failures. Failures roll back the domain change and its transactional event/job records.

## Schema 1

Version 1.0.0 introduces schema 1. There is no previously released schema to transform. Activation uses `dbDelta`, checks database errors, writes the version only after all table operations succeed, and records a sanitized migration failure for administrators. Repeated migration is tested. Loading an older plugin against a higher schema version refuses to downgrade. A partially initialized schema can be retried after database permissions are repaired; DDL itself is not transactionally rolled back by MySQL.

For subsequent releases, increment the schema version, add an explicit ordered migration from each supported predecessor, test a backup-based upgrade and repeated execution, and keep old-code compatibility or document the minimum rollback boundary. Do not change schema 1 after distributing this release. Before any upgrade, back up root tables, WordPress user metadata, and configuration. Restore a compatible plugin and its matching database backup together when a schema change cannot be reversed safely.

## Retention and removal

Hourly maintenance removes expired snoozes and limits, events older than the configured 90-day default, aggregates older than the 365-day default, and completed/failed job history beyond 30 days, in batches of at most 1,000 rows per table per run. Both configurable retention periods are limited to 730 days. Moderation history, discussions, and the credit ledger are retained until member erasure or explicit uninstall cleanup; site administrators must define their operational retention policy for these records.

Uninstall preserves community data by default. Explicit `delete_on_uninstall` drops only these allowlisted tables, removes plugin options/capabilities and `_bpi_` metadata, and clears SQL snapshots. Native accounts, activity, groups, posts, and friendship tables remain owned by their upstream applications. Deactivation clears plugin cron without deleting records.
