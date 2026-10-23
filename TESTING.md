# Verification / 验证

Use PHP ^8.2 with pdo_sqlite and a Composer 2 release supporting strict ambiguous-class checks. The consumer tool additionally needs ext-zip. Development tools come from the committed composer.lock; the runtime has no third-party Composer packages.

```sh
composer install --no-interaction --prefer-dist --no-plugins
composer validate --strict
composer dump-autoload --optimize --strict-psr --strict-ambiguous --no-plugins --no-scripts
composer check
php tools/consumer-check.php
php examples/basic.php
```

If Composer is a PHAR outside PATH, set PRINT_DISPATCH_COMPOSER to its absolute path when running the consumer tool. Use an isolated Composer home/cache as needed; never put auth.json, tokens, vendor or generated state in the repository.

The suite uses random temporary private state and synthetic documents. CUPS tests invoke only generated PHP executables, never the system lp. Coverage includes request/CLI validation, explicit transport and simulation boundaries, idempotency/conflicts, spool paths/permissions/hash integrity, persisted option corruption, bounded safe retries, lease fencing/expiry, process death, persistence failure before/after the submission marker, redaction, output/time limits and concurrent first-open producers/workers. SQLite triggers simulate write failure; they do not emulate an actual disk failure.

The offline consumer tool creates and inspects a Composer ZIP, installs a copied path dependency from the extracted archive with Packagist/network disabled, hides that source directory, runs strict autoload checks, loads all runtime types from the consumer vendor tree, and exercises the library and installed CLI. This verifies source independence; it does not prove a remote registry release.

The public-preparation local run used macOS arm64 and PHP 8.4. The declared CI matrix covers macOS/Linux with PHP 8.2–8.5, but a matrix definition is not a verified CI result. No Linux run, other PHP runtime version, real CUPS scheduler/printer, physical output, Windows, power-loss or production environment was validated locally. Do not infer physical success from submitted or from passing tests. See README.md and SECURITY.md for recovery constraints.

本文件保留可复现命令和测试范围，不包含个人机器路径、账户资料或内部验收日志。完整本地审计记录由维护者在仓库外保存。测试和 CI 不自动公开仓库或发布 Packagist。
