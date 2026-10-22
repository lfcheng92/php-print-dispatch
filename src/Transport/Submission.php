<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge\Transport;

use Lfcheng\PrintBridge\Job;

final readonly class Submission
{
    public function __construct(public Job $job, public string $documentPath)
    {
    }
}
