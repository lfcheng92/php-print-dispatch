# Changelog

## Unreleased — PrintDispatch naming before public release

- Rename the project to PrintDispatch, Composer package to `lfcheng92/print-dispatch`, PHP namespace to `Lfcheng\PrintDispatch\`, and CLI to `bin/print-dispatch`.
- Update imports, examples, test fixtures, CLI help and CUPS job-title branding; queue/storage/retry behavior, dependencies and license are unchanged.
- Earlier private-candidate consumers must update package requirements, FQCNs and CLI paths; no legacy class aliases or executable proxy are retained.
- Preserve existing Git history. Server-side repository rename is a separate pending action while browser operations are prohibited.

## Unreleased — local first version

- Fix CI/runtime handling when CLI argv is unavailable; report a clear error without warnings.
- Fix concurrent first-open WAL transitions with a five-second SQLITE_BUSY retry budget before applying the normal SQLite busy timeout.

- Fix: CLI work now requires explicit --transport and rejects fake with CUPS-only options before claiming jobs.
- Fix: fake success persists as simulated with no OS job ID, cannot directly purge payload, and requires explicit retry/cancel; injected submitted outcomes are rejected.
- Add regression and installed-consumer coverage for missing/mixed transport options and simulation/actual-queue handoff.

- Pure PHP PSR-4 library and Composer CLI worker; no runtime Composer dependencies.
- Private SQLite/WAL queue and controlled spool with bounded byte input and integrity checks.
- Persistent business idempotency, conflict detection and retained tombstones after payload purge.
- Atomic claims, lease fencing, crash recovery, safe bounded retry and explicit unknown reconciliation.
- FakeTransport and conservative macOS/Linux local CUPS adapter using direct argv execution.
- Redacted structured local logs, Chinese/English docs, provenance/licensing boundary.
- Synthetic tests, single-process PHPStan, syntax checks, offline copied-archive consumer verification and CI configuration.

No repository upload, public publication, Packagist release, production access or real printing is included in this local milestone.
