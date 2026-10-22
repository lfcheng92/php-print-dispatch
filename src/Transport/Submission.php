<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch\Transport;

use Lfcheng\PrintDispatch\Job;

final readonly class Submission
{
    public function __construct(public Job $job, public string $documentPath)
    {
    }
}
