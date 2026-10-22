<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
