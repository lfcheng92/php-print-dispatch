<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

enum Status: string
{
    case Received = 'received';
    case Leased = 'leased';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Simulated = 'simulated';
    case RetryWait = 'retry_wait';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';
}
