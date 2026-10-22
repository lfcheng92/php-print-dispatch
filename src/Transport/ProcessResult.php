<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge\Transport;

use Lfcheng\PrintBridge\FailureCode;

final readonly class ProcessResult
{
    public function __construct(
        public bool $started,
        public ?int $exitCode = null,
        public string $stdout = '',
        public bool $timedOut = false,
        public bool $outputExceeded = false,
        public ?FailureCode $failure = null,
    ) {
    }
}
