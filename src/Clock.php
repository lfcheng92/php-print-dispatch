<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

interface Clock
{
    public function now(): int;
}
