# PrintDispatch

[GitHub repository](https://github.com/lfcheng92/php-print-dispatch) · `lfcheng92/print-dispatch` · `Lfcheng\PrintDispatch\`

[中文](README.md)

`lfcheng92/print-dispatch` is a framework-independent PHP Composer library and CLI worker with a persistent SQLite queue, private local spool, business idempotency, lease recovery and conservative CUPS submission semantics. There are no runtime Composer dependencies, Node.js, Electron, HTTP listeners or persistent account tokens.

**First usable candidate; no stable tag or Packagist release.** The macOS/Linux CUPS adapter has only been exercised through isolated executable fixtures. Real hardware validation is pending. Windows is unsupported. `submitted` means scheduler acceptance; `physical_output` always remains `unverified`. End-to-end exactly-once delivery is not guaranteed.

## Requirements and installation

PHP `^8.2` with PDO, pdo_sqlite, json and hash. CLI checks the actual `$argv` input and exits with code 1 without claiming work if it is missing or not a non-empty list of strings. Disabling `register_argc_argv` removes CLI arguments on PHP 8.2–8.4; PHP 8.5 CLI always supplies arguments, independently of this setting. Use a private local state directory (0700, files 0600) under a trusted existing parent, outside the source tree and web root. No network filesystem or shared multi-host queue. CUPS additionally requires a configured macOS/Linux `lp` and enabled `proc_open`; this package never installs, discovers or changes printers.

Accepted input is PDF (header check only) or UTF-8 plain text without unsafe control codes, up to 10 MiB by default, 1–99 copies. A PDF header check is not sanitization: only submit trusted documents and maintain system filters separately.

```sh
composer install --no-interaction --prefer-dist --no-plugins
composer validate --strict
composer check
php tools/consumer-check.php
```

This package is not published on Packagist. For a local consumer use:

```json
{
  "repositories": [{"type":"path","url":"../php-print-dispatch","options":{
    "symlink":false,"versions":{"lfcheng92/print-dispatch":"dev-main"}
  }}],
  "require":{"lfcheng92/print-dispatch":"dev-main"}
}
```

Run `composer install --no-dev --no-plugins --no-scripts`. The installed entry point is `vendor/bin/print-dispatch`.

## CLI

The following uses FakeTransport only; it never contacts CUPS. Set STATE to a new absolute path under your own existing private parent.

```sh
STATE=/absolute/private-parent/print-dispatch-state
php bin/print-dispatch init --state "$STATE"
printf 'Synthetic text\n' | php bin/print-dispatch submit --state "$STATE" \
  --key synthetic-001 --printer demo --mime text/plain
php bin/print-dispatch work --state "$STATE" --once --transport fake
php bin/print-dispatch status --state "$STATE" --id JOB_ID_FROM_SUBMIT
```

Every command requires `--state DIR`. `submit` reads stdin only; there are no file/URL, remote-server or arbitrary CUPS-option inputs. Options use `--name VALUE`; unknown and duplicate options fail.

| Command | Behavior |
| --- | --- |
| `init` | Create/open private storage |
| `submit --key KEY --printer NAME --mime TYPE [--copies 1] [--max-attempts 3]` | Persist request and return received |
| `status --id ID` | Read a durable snapshot; no printer probes or implicit lease recovery |
| `work --once --transport fake\|cups` | Explicit transport required; fake only produces simulated |
| `work --max-jobs N --transport fake\|cups` | Positive N: drain up to N and exit when idle; 0: poll continuously, optional pcntl signal handling |
| `recover` | Recover expired leases; claiming also does this |
| `retry --id ID` | Explicitly requeue a simulated job or a proven not-submitted failure with intact payload; one extra attempt after exhaustion, lifetime cap 100 |
| `resolve --id ID --outcome submitted --evidence TICKET --system-job-id demo-42` | Human confirmation of scheduler acceptance |
| `resolve --id ID --outcome not-submitted --evidence TICKET` | Human proof that submission never occurred; sets failed, does not automatically retry |
| `cancel --id ID` | Cancel received/retry_wait/failed/simulated only; never cancels an OS job |
| `purge --id ID` | Remove submitted/cancelled payload, keep idempotency tombstone |
| `prune` | Delete unreferenced regular spool files older than one hour |

stdout is JSON lines. stderr contains fixed error codes, never raw exception/command output. Exit codes: 0 normal/idle (read the JSON for retry_wait), 1 storage/operation failure, 2 invalid input/not found/idempotency conflict, 3 unknown worker result, 4 failed worker result. Private logs in `STATE/logs/events.jsonl` contain only timestamp, internal job ID, status, attempts and fixed error code. No content, business key, printer, path, lease token, stdout/stderr or exception message is logged. Library callers explicitly supply AuditLogger.

There is no default transport. Fake plus CUPS-only `--lp`/`--timeout` is rejected before claiming a job. Simulation persists as **simulated**, with JSON `simulated: true` and a null system_job_id; it never proves scheduler acceptance. Using fake on an actual queue leaves the claimed job simulated: CUPS will not automatically claim it, and direct purge is forbidden. Explicit retry is required before choosing cups for a real attempt. To deliberately discard it, cancel first, then purge. Use separate synthetic state for demonstrations rather than consuming actual business work.

CUPS requires deliberate `--transport cups --printers NAME[,NAME]`. Administrators may set absolute `--lp`, `--timeout` (default 10s) and `--lease` (default 45s). **Enabling this mode submits real work; local validation did not execute the system lp.** The adapter fixes the scheduler to `localhost:631`, passes an argv array directly without a shell, terminates options with `--`, uses a generated job title and explicit MIME, and does not inherit account secrets or a remote CUPS environment. Simple allowlisted names only; no remote server, instances or arbitrary driver options.

## Library API

```php
use Lfcheng\PrintDispatch\{PrintRequest, Queue, Worker};
use Lfcheng\PrintDispatch\Transport\FakeTransport;

$queue = new Queue('/absolute/private-parent/state');
$job = $queue->enqueue('synthetic-order-1', new PrintRequest('demo', "Synthetic text\n", 'text/plain'));
$result = (new Worker($queue, new FakeTransport(['demo'])))->runOnce(); // simulated Job or null
$snapshot = $queue->get($job->id);
```

The same key and original parameters return the same job, including terminal/purged jobs. Different content, printer, MIME, copies or original maxAttempts raise IdempotencyConflict. Keys are scoped to one persistent database. `examples/basic.php` is runnable and creates private synthetic temporary state.

| API/file | Purpose |
| --- | --- |
| `Queue.php` | enqueue/get/recover/retry/resolveUnknown/cancel/purgeDocument/pruneOrphans |
| `PrintRequest.php` | Immutable input and basic format validation |
| `Job.php`, `Status.php` | Immutable snapshots and controlled JSON |
| `Worker.php` | runOnce, durable submission boundary and fencing |
| `Transport/Transport.php` | maximumRunSeconds and submit(Submission): TransportResult |
| `FakeTransport.php`, `CupsTransport.php` | Simulation / local CUPS |
| `TransportResult.php` | submitted / notSubmitted / unknown factories |
| `AuditLogger.php`, `Clock.php` | Redacted JSONL and injectable test clock |

Queue claim/beginSubmission/finish/failBeforeSubmission/documentPath form the internal worker protocol, not an authentication boundary. InvalidArgumentException means bad input; IdempotencyConflict means key/parameter mismatch; OutOfBoundsException means no such job; LeaseLost means ownership expired; BridgeException/PDOException mean storage/state errors. Do not expose raw library exception messages to untrusted clients.

## State and recovery guarantees

| State | Meaning / recovery |
| --- | --- |
| received | Spool and queue record accepted; claimable |
| leased | Exclusive lease before durable submit marker; expired leases return to received |
| submitting | Durable marker precedes adapter invocation; expired leases become unknown |
| submitted | Recognized scheduler receipt or explicit human confirmation; never proof of physical output |
| simulated | Simulation only, no system receipt; no automatic claim/direct purge, explicit retry or cancel required |
| retry_wait | Proven not-submitted transient failure; bounded exponential backoff |
| failed | Safe failure exhausted / manual confirmation / local integrity failure; no automatic claim |
| unknown | Submission may have happened; no automatic retry |
| cancelled | Cancelled before OS submission; never claimed |

SQLite BEGIN IMMEDIATE serializes producers/claims, and random lease tokens fence stale state writes. Attempts increment at the durable submitting marker. There is no heartbeat; the lease must exceed the adapter's hard runtime bound by at least five seconds, including CUPS termination grace. Host suspension, clock changes and delayed storage can conservatively produce unknown.

Only adapters that can prove no submission occurred may return notSubmitted. CUPS nonzero exits, timeouts, excess output, unexpected receipts and adapter exceptions are unknown. Initial maxAttempts is 1–10, default 3, with 10/20/40/80/160/300-second capped backoff. Missing executable and disallowed printer require configuration repair plus explicit retry. Missing/tampered/nonprivate payload and invalid persisted printer/MIME/copy options fail as spool_invalid before the submission marker and cannot be retried. The low-level CUPS entry point independently rejects invalid options as non-retryable invalid_request. This does not authenticate SQLite metadata or protect against hostile edits.

An early private commit recorded fake receipts as submitted. Old rows are not automatically reinterpreted or replayed. If FakeTransport was used with that version, stop workers and independently reconcile affected jobs before cleanup; old fake-* identifiers do not prove scheduler acceptance. A real CUPS destination can itself be named fake, so prefix-only migration would be unsafe. FakeTransport now refuses injected submitted outcomes and returns only a simulation receipt or explicit failure/unknown fixtures.

Before reconciling unknown, **stop all old workers and child processes, including paused ones**. Independently prove whether a job was ever accepted/output. Absence from today's queue is not proof: completed jobs can disappear. Keep unknown if proof is unavailable. Record a non-secret evidence reference, resolve, then explicitly retry only if never-submitted is established. SQLite and the scheduler have no shared transaction; a paused process or incorrect human judgment can still cause duplicates.

## Operational limits

- Same trusted local user, optionally multiple workers. No hostile same-user processes, Windows support or network filesystem. Private storage does not isolate the owner/root or a malicious printer driver.
- Payload is exclusively created, written and fsynced before the SQLite commit; WAL/FULL/busy_timeout are used. Process crashes may leave orphan payloads; prune handles these. No extra guarantee against power loss, disk failure or corruption; missing payload fails before printing.
- Tombstones are retained indefinitely. Logs/metadata have no automatic rotation. Operators must set quotas, retention and monitoring. Purge payloads deliberately; deleting the database removes idempotency protection.
- Stop workers and consistently back up DB/WAL and spool, or use a proper SQLite backup scheme. Never copy only a live `.sqlite` file. Reconcile after restoring old backups before resuming work.
- No HTTP service, network listener, URL/file ingress, printer discovery, physical completion query, OS cancellation, PDF renderer or arbitrary job options. Future HTTP ingress needs separate loopback/authentication/origin/size design.
- A custom adapter's notSubmitted classification is trusted and can cause duplicates if wrong. Log failure may return CLI exit 1 after a durable successful submission; query the existing job before any action, never invent a new business key to retry.

## Verification and licensing

Tests use temporary private SQLite state, FakeTransport and executable PHP fixtures, with real subprocess termination and concurrency. No system lp, production service or customer data is used. PHPStan level 8 runs with `--debug` to avoid parallel-coordination sockets. Consumer verification additionally needs ext-zip and disables the network/Packagist, inspects a Composer ZIP, installs a copied path dependency from the extracted archive, hides its source, then exercises the library and installed bin proxy. CI declares macOS/Linux with PHP 8.2–8.5; the latest remote CI result has not been verified. Reproducible commands, coverage and limits are in [TESTING.md](TESTING.md).

The independently authored package is licensed under MIT; see [LICENSE](LICENSE), [PROVENANCE.md](PROVENANCE.md), [SECURITY.md](SECURITY.md) and [CHANGELOG.md](CHANGELOG.md). A fresh implementation does not automatically settle ownership. External runtimes, drivers and documents retain their own terms. Repository publication and Packagist release are separately approved operations; passing tests does not trigger either.
