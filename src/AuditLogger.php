<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

use Lfcheng\PrintBridge\Exception\BridgeException;

/** Allowlisted structured fields only: no document, key, token, path, printer, stdout or stderr. */
final class AuditLogger
{
    private string $path;

    public function __construct(string $logDirectory)
    {
        $this->path = PrivateFiles::directory($logDirectory) . '/events.jsonl';
        if (!file_exists($this->path) && !is_link($this->path)) {
            try {
                fclose(PrivateFiles::create($this->path));
            } catch (BridgeException $error) {
                if (!file_exists($this->path)) {
                    throw $error;
                }
            }
        }
        PrivateFiles::assertFile($this->path);
    }

    public function record(Job $job): void
    {
        PrivateFiles::assertFile($this->path);
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new BridgeException('Cannot open audit log.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new BridgeException('Cannot lock audit log.');
            }
            PrivateFiles::write($handle, json_encode([
                'time' => time(), 'event' => 'job_state', 'job_id' => $job->id,
                'status' => $job->status->value, 'attempts' => $job->attempts,
                'error_code' => $job->lastError?->value,
            ], JSON_THROW_ON_ERROR) . "\n");
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
