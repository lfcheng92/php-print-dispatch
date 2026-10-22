<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

use Lfcheng\PrintBridge\Exception\BridgeException;
use Lfcheng\PrintBridge\Exception\IdempotencyConflict;
use Lfcheng\PrintBridge\Exception\LeaseLost;
use Lfcheng\PrintBridge\Transport\TransportResult;

final class Queue
{
    private \PDO $db;
    private string $spool;
    private Clock $clock;

    public function __construct(
        string $stateDirectory,
        private readonly int $maxDocumentBytes = 10_485_760,
        ?Clock $clock = null,
    ) {
        if ($maxDocumentBytes < 1 || $maxDocumentBytes > 104_857_600) {
            throw new \InvalidArgumentException('Document limit must be 1..104857600 bytes.');
        }
        $root = PrivateFiles::directory($stateDirectory);
        $this->spool = PrivateFiles::directory($root . '/spool');
        $this->clock = $clock ?? new SystemClock();
        $path = $root . '/queue.sqlite';
        if (!file_exists($path) && !is_link($path)) {
            try {
                fclose(PrivateFiles::create($path));
            } catch (BridgeException $error) {
                if (!file_exists($path)) {
                    throw $error;
                }
            }
        }
        PrivateFiles::assertFile($path);
        $this->db = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout=0');
        $this->enableWal();
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->transaction(function (): void {
            $versionQuery = $this->db->query('PRAGMA user_version');
            if ($versionQuery === false) {
                throw new BridgeException('Cannot read queue schema version.');
            }
            $version = (int) $versionQuery->fetchColumn();
            if ($version > 1) {
                throw new BridgeException('Queue schema is newer than this library.');
            }
            $this->db->exec('CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY, idempotency_hash TEXT NOT NULL UNIQUE,
                fingerprint TEXT NOT NULL, status TEXT NOT NULL, printer TEXT NOT NULL,
                media_type TEXT NOT NULL, copies INTEGER NOT NULL, content_hash TEXT NOT NULL,
                byte_count INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
                available_at INTEGER NOT NULL, lease_token TEXT, lease_until INTEGER,
                system_job_id TEXT, last_error TEXT, retry_safe INTEGER NOT NULL DEFAULT 1,
                evidence_reference TEXT, document_purged INTEGER NOT NULL DEFAULT 0
            )');
            $this->db->exec('CREATE INDEX IF NOT EXISTS jobs_ready ON jobs(status, available_at, created_at)');
            $this->db->exec('PRAGMA user_version=1');
        });
    }

    public function enqueue(string $idempotencyKey, PrintRequest $request, int $maxAttempts = 3): Job
    {
        if (preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}\z~D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Idempotency key must be 1..128 simple ASCII characters.');
        }
        if (strlen($request->bytes) > $this->maxDocumentBytes || $maxAttempts < 1 || $maxAttempts > 10) {
            throw new \InvalidArgumentException('Document too large or max attempts outside 1..10.');
        }
        return $this->transaction(function () use ($idempotencyKey, $request, $maxAttempts): Job {
            $keyHash = hash('sha256', $idempotencyKey);
            // Retry policy is part of the idempotent request, too.
            $fingerprint = hash('sha256', $request->fingerprint() . ':' . $maxAttempts);
            $existing = $this->row('SELECT * FROM jobs WHERE idempotency_hash = ?', [$keyHash]);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                    throw new IdempotencyConflict('Idempotency key already has different parameters.');
                }
                return $this->job($existing);
            }
            $id = bin2hex(random_bytes(16));
            $path = $this->spool . '/' . $id . '.data';
            $handle = PrivateFiles::create($path);
            try {
                PrivateFiles::write($handle, $request->bytes);
            } catch (\Throwable $error) {
                fclose($handle);
                @unlink($path);
                throw $error;
            }
            fclose($handle);
            $now = $this->clock->now();
            try {
                $this->execute('INSERT INTO jobs
                    (id,idempotency_hash,fingerprint,status,printer,media_type,copies,content_hash,
                    byte_count,max_attempts,created_at,updated_at,available_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $id, $keyHash, $fingerprint, Status::Received->value, $request->printer,
                    $request->mediaType, $request->copies, hash('sha256', $request->bytes),
                    strlen($request->bytes), $maxAttempts, $now, $now, $now,
                ]);
            } catch (\Throwable $error) {
                @unlink($path);
                throw $error;
            }
            return $this->get($id);
        });
    }

    public function get(string $id): Job
    {
        self::validateId($id);
        $row = $this->row('SELECT * FROM jobs WHERE id = ?', [$id]);
        if ($row === null) {
            throw new \OutOfBoundsException('Job does not exist.');
        }
        return $this->job($row);
    }

    /** Atomically recover expired leases and claim a single eligible job. */
    public function claim(int $leaseSeconds): ?Claim
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Lease must be 5..3600 seconds.');
        }
        return $this->transaction(function () use ($leaseSeconds): ?Claim {
            $now = $this->clock->now();
            $this->recoverInside($now);
            $row = $this->row('SELECT * FROM jobs WHERE status IN (?,?) AND available_at <= ?
                ORDER BY created_at, id LIMIT 1', [Status::Received->value, Status::RetryWait->value, $now]);
            if ($row === null) {
                return null;
            }
            $id = (string) $row['id'];
            $token = bin2hex(random_bytes(24));
            $this->execute('UPDATE jobs SET status=?,lease_token=?,lease_until=?,updated_at=? WHERE id=?',
                [Status::Leased->value, $token, $now + $leaseSeconds, $now, $id]);
            return new Claim($this->get($id), $token);
        });
    }

    /** @internal Durable boundary: every call after this point may have reached the OS. */
    public function beginSubmission(Claim $claim, int $leaseSeconds): Job
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Lease must be 5..3600 seconds.');
        }
        $now = $this->clock->now();
        $changed = $this->execute('UPDATE jobs SET status=?,attempts=attempts+1,lease_until=?,updated_at=?,
            last_error=NULL WHERE id=? AND status=? AND lease_token=? AND lease_until>?', [
            Status::Submitting->value, $now + $leaseSeconds, $now,
            $claim->job->id, Status::Leased->value, $claim->token, $now,
        ]);
        if ($changed !== 1) {
            throw new LeaseLost('Lease expired or is no longer owned.');
        }
        return $this->get($claim->job->id);
    }

    /** @internal A token fences stale workers from changing a recovered job. */
    public function finish(Claim $claim, TransportResult $result): Job
    {
        return $this->transaction(function () use ($claim, $result): Job {
            $now = $this->clock->now();
            $current = $this->get($claim->job->id);
            $status = match ($result->outcome) {
                'submitted' => Status::Submitted,
                'simulated' => Status::Simulated,
                'unknown' => Status::Unknown,
                default => $result->retryable && $current->attempts < $current->maxAttempts
                    ? Status::RetryWait : Status::Failed,
            };
            $delay = $status === Status::RetryWait ? min(300, 5 * (2 ** min(6, $current->attempts))) : 0;
            $changed = $this->execute('UPDATE jobs SET status=?,system_job_id=?,last_error=?,retry_safe=?,
                available_at=?,lease_token=NULL,lease_until=NULL,updated_at=?
                WHERE id=? AND status=? AND lease_token=? AND lease_until>?', [
                $status->value, $result->systemJobId, $result->failure?->value,
                in_array($result->outcome, ['not_submitted', 'simulated'], true) ? 1 : 0, $now + $delay, $now,
                $current->id, Status::Submitting->value, $claim->token, $now,
            ]);
            if ($changed !== 1) {
                throw new LeaseLost('Submission result arrived after lease ownership ended.');
            }
            return $this->get($current->id);
        });
    }

    /** @internal A pre-submit local integrity failure is known not to have reached the OS. */
    public function failBeforeSubmission(Claim $claim): Job
    {
        $now = $this->clock->now();
        $changed = $this->execute('UPDATE jobs SET status=?,last_error=?,retry_safe=0,
            lease_token=NULL,lease_until=NULL,updated_at=?
            WHERE id=? AND status=? AND lease_token=? AND lease_until>?', [
            Status::Failed->value, FailureCode::SpoolInvalid->value, $now,
            $claim->job->id, Status::Leased->value, $claim->token, $now,
        ]);
        if ($changed !== 1) {
            throw new LeaseLost('Cannot fail a job without its active lease.');
        }
        return $this->get($claim->job->id);
    }

    public function recover(): int
    {
        return $this->transaction(fn (): int => $this->recoverInside($this->clock->now()));
    }

    /** Explicitly requeue a simulation or authorize an extra proven-not-submitted attempt. */
    public function retry(string $id): Job
    {
        self::validateId($id);
        $now = $this->clock->now();
        $changed = $this->execute('UPDATE jobs SET status=?,available_at=?,updated_at=?,
            max_attempts=CASE WHEN attempts>=max_attempts THEN attempts+1 ELSE max_attempts END
            WHERE id=? AND status IN (?,?) AND retry_safe=1 AND document_purged=0 AND attempts<100', [
            Status::Received->value, $now, $now, $id, Status::Failed->value, Status::Simulated->value,
        ]);
        if ($changed !== 1) {
            throw new BridgeException('Only a simulation or proven not-submitted failure can be retried (up to 100 attempts).');
        }
        return $this->get($id);
    }

    /** Human reconciliation records a non-secret ticket/reference; it never resubmits by itself. */
    public function resolveUnknown(string $id, bool $confirmedSubmitted, string $evidenceReference, ?string $systemJobId = null): Job
    {
        self::validateId($id);
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $evidenceReference) !== 1) {
            throw new \InvalidArgumentException('Use a non-secret evidence reference (1..80 simple characters).');
        }
        if ($confirmedSubmitted) {
            TransportResult::validateSystemJobId($systemJobId ?? '');
        } elseif ($systemJobId !== null) {
            throw new \InvalidArgumentException('An absent job cannot have a system job ID.');
        }
        $changed = $this->execute('UPDATE jobs SET status=?,system_job_id=?,last_error=?,retry_safe=?,
            evidence_reference=?,updated_at=? WHERE id=? AND status=?', [
            $confirmedSubmitted ? Status::Submitted->value : Status::Failed->value,
            $systemJobId, $confirmedSubmitted ? null : FailureCode::OperatorConfirmedAbsent->value,
            $confirmedSubmitted ? 0 : 1, $evidenceReference, $this->clock->now(), $id, Status::Unknown->value,
        ]);
        if ($changed !== 1) {
            throw new BridgeException('Only an unknown job can be reconciled.');
        }
        return $this->get($id);
    }

    public function cancel(string $id): Job
    {
        self::validateId($id);
        $changed = $this->execute('UPDATE jobs SET status=?,retry_safe=0,updated_at=? WHERE id=? AND status IN (?,?,?,?)', [
            Status::Cancelled->value, $this->clock->now(), $id,
            Status::Received->value, Status::RetryWait->value, Status::Failed->value, Status::Simulated->value,
        ]);
        if ($changed !== 1) {
            throw new BridgeException('Job is already active or cannot be cancelled.');
        }
        return $this->get($id);
    }

    /** Delete payload only. Keep the idempotency tombstone indefinitely. */
    public function purgeDocument(string $id): Job
    {
        self::validateId($id);
        return $this->transaction(function () use ($id): Job {
            $job = $this->get($id);
            if (!in_array($job->status, [Status::Submitted, Status::Cancelled], true)) {
                throw new BridgeException('Only submitted or cancelled documents can be purged.');
            }
            $path = $this->spool . '/' . $id . '.data';
            if (file_exists($path) || is_link($path)) {
                PrivateFiles::assertFile($path);
                if (!unlink($path)) {
                    throw new BridgeException('Cannot purge document.');
                }
            }
            $this->execute('UPDATE jobs SET document_purged=1,updated_at=? WHERE id=?', [$this->clock->now(), $id]);
            return $this->get($id);
        });
    }

    /** Remove only old, unreferenced regular spool files, under the queue write lock. */
    public function pruneOrphans(int $minimumAgeSeconds = 3600): int
    {
        if ($minimumAgeSeconds < 60) {
            throw new \InvalidArgumentException('Orphan grace period must be at least 60 seconds.');
        }
        return $this->transaction(function () use ($minimumAgeSeconds): int {
            $count = 0;
            foreach (new \DirectoryIterator($this->spool) as $file) {
                if (preg_match('/\A([a-f0-9]{32})\.data\z/D', $file->getFilename(), $match) !== 1
                    || $file->isLink() || !$file->isFile() || $file->getMTime() > $this->clock->now() - $minimumAgeSeconds) {
                    continue;
                }
                if ($this->row('SELECT id FROM jobs WHERE id=?', [$match[1]]) === null) {
                    PrivateFiles::assertFile($file->getPathname());
                    if (!unlink($file->getPathname())) {
                        throw new BridgeException('Cannot prune orphan document.');
                    }
                    ++$count;
                }
            }
            return $count;
        });
    }

    /** @internal Returns only a generated spool path after integrity checking. */
    public function documentPath(Job $job): string
    {
        self::validateId($job->id);
        $path = $this->spool . '/' . $job->id . '.data';
        PrivateFiles::assertFile($path);
        if ($job->documentPurged || @filesize($path) !== $job->byteCount
            || !hash_equals($job->contentHash, (string) @hash_file('sha256', $path))) {
            throw new BridgeException('Spool integrity check failed.');
        }
        return $path;
    }

    /** SQLite can reject concurrent first-open WAL transitions without invoking busy_timeout. */
    private function enableWal(): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (true) {
            try {
                $this->db->exec('PRAGMA journal_mode=WAL');
                return;
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 5 || hrtime(true) >= $deadline) {
                    throw $error;
                }
                usleep(10_000);
            }
        }
    }

    private function recoverInside(int $now): int
    {
        $a = $this->execute('UPDATE jobs SET status=?,lease_token=NULL,lease_until=NULL,updated_at=?
            WHERE status=? AND lease_until<=?', [Status::Received->value, $now, Status::Leased->value, $now]);
        $b = $this->execute('UPDATE jobs SET status=?,last_error=?,retry_safe=0,lease_token=NULL,lease_until=NULL,updated_at=?
            WHERE status=? AND lease_until<=?', [
            Status::Unknown->value, FailureCode::LeaseExpired->value, $now, Status::Submitting->value, $now,
        ]);
        return $a + $b;
    }

    private static function validateId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid job ID.');
        }
    }

    /** @param list<string|int|null> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        return $query->rowCount();
    }

    /** @param list<string|int|null> $parameters
     *  @return array<string, mixed>|null */
    private function row(string $sql, array $parameters): ?array
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        /** @var array<string, mixed>|false $row */
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function job(array $row): Job
    {
        return new Job(
            (string) $row['id'], Status::from((string) $row['status']), (string) $row['printer'],
            (string) $row['media_type'], (int) $row['copies'], (string) $row['content_hash'],
            (int) $row['byte_count'], (int) $row['attempts'], (int) $row['max_attempts'],
            (int) $row['created_at'], (int) $row['updated_at'], (int) $row['available_at'],
            $row['lease_until'] === null ? null : (int) $row['lease_until'],
            $row['system_job_id'] === null ? null : (string) $row['system_job_id'],
            $row['last_error'] === null ? null : FailureCode::from((string) $row['last_error']),
            (bool) $row['retry_safe'], (bool) $row['document_purged'],
        );
    }

    /** @template T
     *  @param \Closure(): T $operation
     *  @return T */
    private function transaction(\Closure $operation): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (\PDOException) {
                // Preserve the original error if COMMIT already ended an uncertain transaction.
            }
            throw $error;
        }
    }
}
