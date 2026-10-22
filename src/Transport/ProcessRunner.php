<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch\Transport;

use Lfcheng\PrintDispatch\FailureCode;

/** Executes an argv array directly. No shell, inherited secrets, or unbounded output. */
final class ProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, int $timeoutSeconds): ProcessResult
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300 || $command === []) {
            throw new \InvalidArgumentException('Invalid command timeout or empty command.');
        }
        foreach ($command as $argument) {
            if (str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('NUL in process argument.');
            }
        }
        if (!str_starts_with($command[0], '/') || !is_file($command[0]) || !is_executable($command[0])) {
            return new ProcessResult(false, failure: FailureCode::MissingExecutable);
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname($command[0]), [
                'PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C', 'LANG' => 'C', 'CUPS_SERVER' => 'localhost:631',
            ], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return new ProcessResult(false, failure: FailureCode::SpawnFailed);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $outputBytes = 0;
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $timeout = false;
        $overflow = false;
        $exitCode = null;
        try {
            while (true) {
                $out = stream_get_contents($pipes[1], 16_385);
                $err = stream_get_contents($pipes[2], 16_385);
                $out = $out === false ? '' : $out;
                $err = $err === false ? '' : $err;
                $outputBytes += strlen($out) + strlen($err);
                $stdout .= substr($out, 0, max(0, 16_384 - strlen($stdout)));
                $status = proc_get_status($process);
                $overflow = $outputBytes > 16_384;
                $timeout = hrtime(true) >= $deadline;
                if ($overflow || $timeout) {
                    proc_terminate($process);
                    usleep(50_000);
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }
                    break;
                }
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    // Drain bounded remaining bytes; pipes remain nonblocking.
                    $tail = stream_get_contents($pipes[1], 16_385);
                    $tailErr = stream_get_contents($pipes[2], 16_385);
                    $tail = $tail === false ? '' : $tail;
                    $tailErr = $tailErr === false ? '' : $tailErr;
                    $outputBytes += strlen($tail) + strlen($tailErr);
                    $stdout .= substr($tail, 0, max(0, 16_384 - strlen($stdout)));
                    $overflow = $outputBytes > 16_384;
                    break;
                }
                usleep(10_000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode === null && !$timeout && !$overflow) {
                $exitCode = $closed;
            }
        }
        return new ProcessResult(true, $exitCode, $stdout, $timeout, $overflow);
    }
}
