<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch;

interface Clock
{
    public function now(): int;
}
