<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch\Transport;

use Lfcheng\PrintDispatch\FailureCode;

final readonly class TransportResult
{
    private function __construct(
        public string $outcome,
        public ?string $systemJobId,
        public ?FailureCode $failure,
        public bool $retryable,
    ) {
    }

    public static function submitted(string $systemJobId): self
    {
        self::validateSystemJobId($systemJobId);
        return new self('submitted', $systemJobId, null, false);
    }

    /** A simulation never produces a system queue receipt. */
    public static function simulated(): self
    {
        return new self('simulated', null, null, false);
    }

    /** Use ONLY when the adapter can prove no submission occurred. */
    public static function notSubmitted(FailureCode $failure, bool $retryable = false): self
    {
        return new self('not_submitted', null, $failure, $retryable);
    }

    public static function unknown(FailureCode $failure): self
    {
        return new self('unknown', null, $failure, false);
    }

    public static function validateSystemJobId(string $id): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,126}-[0-9]{1,12}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid system job ID.');
        }
    }
}
