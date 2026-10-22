<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch;

/** Internal worker lease. Keep its token private. */
final readonly class Claim
{
    public function __construct(public Job $job, public string $token)
    {
    }
}
