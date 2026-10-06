# REST API v1

Namespace: `/wp-json/buddypress-intelligence/v1`. Use WordPress cookie authentication plus `X-WP-Nonce` for browser writes, or standard WordPress application-password authentication for a trusted client. The actor always comes from the authenticated session. Anonymous requests are denied. Nonces prove request intent and never replace capability or object checks.

All responses are private and non-cacheable. Mutation bodies are JSON. IDs must be positive integers, unrecognized fields are rejected at service boundaries, strings/arrays/booleans have explicit validation and bounds. Collection page numbers are 1–1,000. Cursor pages default to 20 items, maximum 50; admin collections return at most 50 records per page. Errors use stable codes and safe messages: authentication 401, capability 403, unavailable object 404, invalid input 400, unsupported method 405, rate limit 429, disabled module 503, operation failure 500.

| Path | Methods | Additional authority / purpose |
|---|---|---|
| `graph` | GET, POST, DELETE | Own graph only; `kind,type,id,days` |
| `preferences` | GET, POST | Own interests/skills/help/goals/groups/privacy/consent |
| `topics` | GET, POST | Creating requires `bpi_manage_topics` |
| `topics/{id}` | GET, POST | Detail/related topics/visible sample; updating requires topic authority |
| `object-topics` | POST | `type,id,topics`; owner or topic manager |
| `feed` | GET | Five feed modes; Activity required |
| `recommendations` | GET | Permitted requested type; expert needs expertise module |
| `search`, `autocomplete` | GET | Keyword/phrase and filters; autocomplete max five items |
| `feedback` | POST | `type,id,value`; more/less/not_interested/clear |
| `questions` | GET, POST | Visible listing / create title, body, topics and group context |
| `answers` | POST | `parent_id,body`; inherited question visibility |
| `knowledge` | GET, POST | Visible listing / moderator-reviewed accepted answer provenance |
| `entries/{id}` | GET | `kind,page`; detail, eligible answers and related questions |
| `accept` | POST | `question_id,answer_id`; question owner |
| `accept/reverse` | POST | `question_id`; `bpi_manage_reputation` |
| `votes` | POST | `answer_id`; no self-voting, mature account |
| `reputation/{id}` | GET | Visible member and topic-specific visible evidence |
| `reports` | GET, POST | Own receipts / eligible `type,id,category,description` |
| `cases` | GET | `bpi_view_cases`; page/status |
| `cases/{id}` | GET, POST | View capability; mutations require moderation and case-view capability |
| `appeals` | GET, POST | Own decisions / subject-only case_id/reason |
| `appeals/{id}` | POST | `bpi_moderate`; pending appeal status/decision |
| `rules`, `rules/{id}` | GET+POST, POST | `bpi_manage_automations`; typed definition |
| `runs` | GET | Automation authority; bounded run history |
| `analytics` | GET | `bpi_view_analytics`; definitions, suppressed aggregates and health components |
| `experiments` | GET, POST | `bpi_manage_feed`; definitions |
| `experiments/{id}` | GET, POST | Feed authority; results / validated lifecycle |
| `experiments/{id}/assignment` | GET | Own deterministic assignment in an active window |
| `intelligence` | POST | `type,id,capability`; consent and public editorial policy |
| `events` | POST | Eligible activity_viewed/search_result_clicked/recommendation_clicked; optional experiment_id is assigned server-side |
| `settings` | GET, POST | `bpi_manage_settings`; AI/transfer fields also need `bpi_manage_ai` |
| `diagnostics` | GET | Settings authority; runtime/schema/jobs/provider-failure metadata |
| `jobs/{id}/retry` | POST | Settings authority; failed jobs only |

Module flags apply independently before dispatch. Capabilities are evaluated on the BuddyPress root. A moderator capability does not bypass a member's custom privacy or bilateral blocks on discovery endpoints.

Feed/search/recommendation inputs: `mode,type,q,page,per_page,cursor,topic_id,author,after,before,unanswered,window`. Modes: `latest,following,for_you,discover,trending`. Types: `all,activity,member,expert,group,topic,post,question,knowledge`. Dates are UTC date or date-time strings. Results include items with `id,type,title,excerpt,url,date,reason`, plus state/page/has_more/cursor/window and, where applicable, more_windows. There is no unauthorized global total count.

Example follow body: `{"kind":"follow","type":"member","id":42}`. Delete the same relationship with DELETE. Snooze accepts 1–90 days; blocking is member-only. The maximum owned graph is 5,000 edges.

Example preferences: `{"interests":[1,2],"skills":[2],"visibility":"followers","analytics":false,"external_ai":false,"onboarded":true}`. Actor IDs, arbitrary metadata and roles are not accepted.

Example question: `{"title":"How should this work?","body":"Context","topics":[2],"group_id":0}`. Answers inherit parent scope; ownership alone does not grant access to private group context.

Example automation: `{"name":"Welcome follower","trigger":"member_followed","conditions":{},"action":"state","config":{"state":"welcomed","value":"yes"},"enabled":true,"dry_run":true}`. Allowed conditions are group_id, topic_id, member_type and minimum_reputation. Actions are notify, state, feature and review. The community permits at most 100 rules; existing rules can still be edited at capacity.

Reports permit at most five writes per member per minute; other mutation and costly discovery channels permit 60 per minute. Limits use atomic database buckets, independent of IP addresses and object-cache availability. Integrators should respect 429 and back off.

Feed snapshots are not permanent bookmarks. Keep all context inputs identical when sending the cursor on the next page. On expiry, refresh the first page. Increment window and omit the old cursor when exploring another candidate window.
