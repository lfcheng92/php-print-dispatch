<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch\Transport;

interface Transport
{
    /** Hard runtime bound; the worker requires a longer lease. */
    public function maximumRunSeconds(): int;

    /** Never throw after OS submission intentionally; exceptions are treated as unknown. */
    public function submit(Submission $submission): TransportResult;
}
