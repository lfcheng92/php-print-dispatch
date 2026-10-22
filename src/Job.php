<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

final readonly class Job implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public Status $status,
        public string $printer,
        public string $mediaType,
        public int $copies,
        public string $contentHash,
        public int $byteCount,
        public int $attempts,
        public int $maxAttempts,
        public int $createdAt,
        public int $updatedAt,
        public int $availableAt,
        public ?int $leaseUntil,
        public ?string $systemJobId,
        public ?FailureCode $lastError,
        public bool $retrySafe,
        public bool $documentPurged,
    ) {
    }

    /** @return array<string, int|string|bool|null> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id, 'status' => $this->status->value,
            'printer' => $this->printer, 'media_type' => $this->mediaType,
            'copies' => $this->copies, 'bytes' => $this->byteCount,
            'attempts' => $this->attempts, 'max_attempts' => $this->maxAttempts,
            'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt,
            'available_at' => $this->availableAt, 'lease_until' => $this->leaseUntil,
            'system_job_id' => $this->systemJobId,
            'simulated' => $this->status === Status::Simulated,
            'last_error' => $this->lastError?->value, 'retry_safe' => $this->retrySafe,
            'document_purged' => $this->documentPurged, 'physical_output' => 'unverified',
        ];
    }
}
