# Verification procedures

These tools create and mutate isolated test data. Never point them at a production configuration. The PHP integration fixture explicitly refuses any database other than `bpi_tests` and any prefix outside `bpit_`. Network and package lifecycle checks use their own prefixes. Test email delivery and external WordPress requests are disabled.

## Reproduce the runtime

Use PHP 8.1+ with mysqli, mbstring, cURL, OpenSSL, ZIP and PHPUnit's standard XML/DOM extensions; Node 24; and MariaDB with JSON functions. Provision a disposable MariaDB instance on `127.0.0.1:33307` with test-only root password `bpi_test_local`. No script starts or reconfigures an existing production database.

From the repository, download official WordPress and pinned BuddyPress sources, install and activate:

```powershell
rtk proxy php tools/download-wordpress.php
rtk proxy php tools/download-runtime.php
rtk proxy php tools/install-runtime.php
rtk proxy php tools/activate-runtime.php
rtk proxy php tools/download-checks.php
rtk proxy php tools/configure-checks.php
rtk proxy php tools/check-style.php
rtk proxy npm ci
```

The local web runtime needs a plugin directory mapping named `wordpress-buddypress-plugin` under its `wp-content/plugins`, pointing to this repository, for asset URLs. Use a Windows junction or Linux symlink only in the isolated runtime. Do not recursively enumerate/delete this tree through that junction. The mu-loader registers its real path. CI creates the symlink explicitly.

The optional Composer definition lists development tools; production does not require Composer. During implementation Packagist connections timed out, so QA used pinned official PHARs and downloaded standards. There is no claimed successful Composer resolution or Composer lockfile. npm has an exact lockfile. `phpstan.neon.dist` reads the prepared `.runtime/wordpress-stubs.php`; verified BuddyPress signatures are in `tests/static`. Stubs support analysis and do not replace the real runtime used by integration tests.

## Execute checks

```powershell
rtk proxy php tools/lint.php
rtk proxy php .runtime/phpcs.phar
rtk proxy php .runtime/phpstan.phar analyse --memory-limit=2G --no-progress
rtk proxy php .runtime/phpunit.phar --log-junit .runtime/phpunit-results.xml
rtk proxy npm test
rtk proxy npm run lint
```

Start the isolated WordPress HTTP server separately on `127.0.0.1:8917`, then run `rtk proxy npm run test:e2e`. Playwright uses installed Chrome on Windows or its own Chromium on Linux. CI installs Chromium. `BPI_BROWSER` selects another executable. The browser suite covers anonymous denial, keyboard preferences, mobile/RTL search, non-JavaScript forms, moderator accessibility, complete answer acceptance/reversal, topic/automation editing, and reporter-to-moderator case processing.

**Run PHP fixtures, component checks, and browser tests sequentially when they share a prefix.** The integration fixture clears plugin tables and preference metadata between tests. Overlapping it with browser interactions invalidates both results. The dedicated WordPress 6.8 runtime uses `bpit_68_` and can run independently after `tools/compatibility-runtime.php` prepares it. `tools/verify-compatibility.php` selects it without depending on PowerShell execution-policy changes.

```powershell
rtk proxy php tools/compatibility-runtime.php
rtk proxy php tools/verify-compatibility.php
rtk proxy php tools/network-setup.php --install
rtk proxy php tools/network-verify.php
rtk proxy php tools/component-verify.php activity
rtk proxy php tools/component-verify.php groups
rtk proxy php tools/component-verify.php friends
rtk proxy php tools/component-verify.php notifications
rtk proxy php tools/message-verify.php
```

Network checks use `bpin_`. Component checks change only the disposable runtime's active-component option and restore it in `finally`. Messaging verification creates native messages schema only in the test database, rejects a blocked recipient before insert, and verifies message count did not change.

## Security and privacy coverage

| Boundary | Executable evidence |
|---|---|
| Authentication/capabilities | Anonymous rejection on all 35 route patterns; ordinary-member admin rejection; actual REST registration; root vs child administration |
| CSRF | Nonce-protected browser and no-JavaScript forms; authenticated same-origin JavaScript; WordPress cookie/REST nonce handling |
| IDOR/mass assignment | Spoofed actor rejection, ownership checks, private-group question/answer denial, invalid HTTP method rejection |
| SQL injection | Prepared repository values and hostile search string regression |
| Stored XSS | Malicious question title/body stripped; provider output sanitized; escaped rendered data |
| SSRF/secrets | Local/link-local/scheme endpoints rejected; public-only provider payload; error/token masking; circuit breaker |
| Privacy across retrieval | Feed/recommendations/search, direct/native REST, nested comments, blocked reputation/answers and caches |
| Integrity | Unique graph/vote/event/run keys, repeated acceptance/reversal, nested rollback, expired-worker lease protection |
| Consent/lifecycle | Dual provider consent, consent-based event/aggregate behavior, query text omission, paginated erasure, persistent-cache cursor invalidation |
| Abuse | Atomic member limits, explicit rule capacity, self-vote/credit prevention, bounded retries/recursion |

Race-sensitive production writes use database constraints, row locks and lease comparisons. Separate processes now also compete through barriers: 12 acceptance requests, 12 votes, 12 automation deliveries, eight rule creators, eight workers draining 120 jobs, and 300 rate-limit requests. Results verify one acceptance/credit/event, one vote/run/notification, rule capacity, no duplicate healthy-worker executions, and the rate ceiling. These are focused integrity checks, not a general throughput or crash/chaos certification.

Repeated worker races exposed a completion deadlock after a successful handler. The fix retries only the conditional state write, outside handler error handling. Real MySQL `SIGNAL` fault injection verifies codes 1213/1205, bounded exhaustion, permanent-error behavior, unchanged handler count and retained running leases. It also verifies transactional retry rejection and failed policy reads stopping serialization. These regressions run in every compatibility cell.

## Executed container matrix and Redis verification

Official WordPress PHP images provide PHP 8.1.34, 8.2.34 and 8.3.35. Unmodified WordPress sources are extracted into each container filesystem. Every PHP/WordPress combination owns a separate `bpit_c...` prefix in the disposable MariaDB database; it never shares the browser fixture. Redis 7.4.11 uses the official Redis Object Cache 2.7.0 drop-in with Predis. The PHP 8.3 / WordPress 7.1.2 cell runs the full suite with Redis enabled. Fresh PHP processes separately verify snapshot persistence, changed bilateral blocks and erasure invalidation. Multisite adds 15 real Redis/root/child checks under `bpin_` in the container database.

Prepare `tools/download-extended-runtime.php`, `tools/download-runtime.php`, `tools/download-checks.php`, and `tools/prepare-container-sources.php` (the latter archives the original unmodified WordPress 7.1 core before upgrading that local source). Start disposable containers named `bpi-qa-mariadb` and `bpi-qa-redis` on a private network `bpi-release-qa`. The pinned image digests and exact service setup are in `.github/workflows/ci.yml`.

Run matrix cells sequentially with this pattern, substituting PHP 8.1/8.2/8.3 and your absolute repository path:

```powershell
rtk proxy docker run --rm --network bpi-release-qa --entrypoint php --mount type=bind,source=D:/prj/wordpress-buddypress-plugin,target=/workspace --workdir /workspace wordpress:php8.3-apache tools/container-matrix.php
rtk proxy docker run --rm --network bpi-release-qa --entrypoint php --mount type=bind,source=D:/prj/wordpress-buddypress-plugin,target=/workspace --workdir /workspace wordpress:php8.3-apache tools/container-extended.php
rtk proxy docker run --rm --network bpi-release-qa --entrypoint php --mount type=bind,source=D:/prj/wordpress-buddypress-plugin,target=/workspace --workdir /workspace wordpress:php8.3-apache tools/container-network.php
```

Matrix JSON/JUnit/logs and extended results are stored in `.runtime/container-qa`; network evidence is `.runtime/container-network-qa/network-results.log`. PHPUnit fails on warnings, notices, deprecations, risky tests and skipped tests. Runners delete stale result files and require explicit completion evidence because WordPress can exit with code zero while displaying a database error page. An interrupted Docker run was recovered and rerun; interrupted cells were not counted as passes. Test mail is suppressed through both WordPress and BuddyPress mail adapters.

## Performance and package checks

`tools/benchmark.php` uses verified synthetic populations of 1,000, 10,000 and 100,000 members, creates 150 public activities at each stage, flushes local object cache and records three For You requests per stage plus indexed query plans. Results are in `.runtime/benchmark.json`. This measures a bounded feed against increasing user-table size, not 100,000 active members, millions of activities/events, concurrent requests or a Redis cluster. Do not extrapolate throughput or p95/p99 latency from nine observations.

```powershell
rtk proxy php tools/benchmark.php
rtk proxy php tools/make-pot.php
rtk proxy php tools/package.php
rtk proxy php tools/release-setup.php
rtk proxy php tools/release-verify.php
```

Package verification hashes every included file and confirms the archive contents. The release allowlist excludes test tools, fixtures, dependency trees, credentials, logs and caches. Fixed ZIP timestamps permit repeated identical builds. Lifecycle verification activates the extracted artifact against the selected isolated WordPress source using WordPress's plugin API in `bpit_release_`, checks all tables/defaults/caps/cron, deactivates, verifies preservation, then verifies explicitly opted-in uninstall cleanup and survival of the native account.

## Practical limits

Actual local results are in [engineering-report.md](engineering-report.md). PHP 8.1/8.2/8.3 compatibility and real Redis checks were executed locally in containers. CI includes the same minimum/current source matrix and a pinned Redis/concurrency/Multisite job, but remote GitHub Actions execution is not claimed. Additional Windows PHP binaries remain unavailable; Linux containers close the language-version gate, and Windows PHP 8.2.12 was verified separately. Composer is an optional development-tool path, not a runtime or release prerequisite; the executed tools are pinned official PHARs. Memcached and other cache products are outside this measured matrix.

Axe checks the plugin's populated surfaces, and screenshots were inspected. This does not certify all surrounding themes, screen readers, translations, browsers, cache products, third-party endpoints, email infrastructure, external providers, or hosting environments. Deployment acceptance should run the supplied suite with the actual theme/plugin set, operational cron, caching rules, backups, and selected provider before enabling it for members.
