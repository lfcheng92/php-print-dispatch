# Changelog

## Unreleased — public preparation review

- Correct CLI argument regression expectations for PHP 8.5, which always supplies CLI arguments regardless of register_argc_argv. Preserve deterministic missing-argument rejection, reject malformed argument lists without warnings or an uncaught TypeError, and cover the installed Composer binary.

- Revalidate persisted print options before submission; fail invalid stored requests without invoking a transport. Independently reject invalid low-level CUPS options.
- Add failure injection for receipt/claim persistence, stale-token reconciliation and concurrent first-open producers with isolated CUPS fixture workers.
- Select MIT for independently authored package code; preserve external dependency and runtime license boundaries.
- Remove machine-specific local acceptance/upload records from the repository; document portable verification in TESTING.md.
- Strengthen copied-archive consumer checks with strict PSR-4/ambiguity checks and loading every runtime type.

## Unreleased — PrintDispatch naming before public release

- Rename the project to PrintDispatch, Composer package to `lfcheng92/print-dispatch`, PHP namespace to `Lfcheng\PrintDispatch\`, and CLI to `bin/print-dispatch`.
- Update imports, examples, test fixtures, CLI help and CUPS job-title branding; queue/storage/retry behavior, dependencies and license are unchanged.
- Earlier private-candidate consumers must update package requirements, FQCNs and CLI paths; no legacy class aliases or executable proxy are retained.
- Preserve existing Git history. The server-side repository rename to php-print-dispatch is complete.

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

The original local milestone did not include repository upload, public publication, Packagist release, production access or real printing. Subsequent repository administration does not imply hardware validation or a Packagist release.
