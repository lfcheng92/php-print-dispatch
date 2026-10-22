# Changelog

## Unreleased — local first version

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
