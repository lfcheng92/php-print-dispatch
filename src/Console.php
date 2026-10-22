<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

use Lfcheng\PrintBridge\Exception\IdempotencyConflict;
use Lfcheng\PrintBridge\Transport\CupsTransport;
use Lfcheng\PrintBridge\Transport\FakeTransport;

final class Console
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        try {
            $command = $arguments[1] ?? 'help';
            if ($command === 'help' || $command === '--help') {
                fwrite(STDOUT, "print-bridge: init | submit | status | work | recover | retry | resolve | cancel | purge | prune\n"
                    . "All commands require --state /absolute/private/directory.\n"
                    . "submit --key KEY --printer NAME --mime text/plain [--copies 1]: document from stdin.\n"
                    . "work [--once | --max-jobs N] [--transport fake|cups] [--printers demo] [--lease 45] [--timeout 10] [--lp /usr/bin/lp]\n"
                    . "status/retry/cancel/purge --id ID\n"
                    . "resolve --id ID --outcome submitted|not-submitted --evidence TICKET [--system-job-id NAME-N]\n"
                    . "CUPS requires an explicit --printers allowlist; submitted never means physical output.\n");
                return 0;
            }
            $allowed = [
                'init' => [], 'submit' => ['key', 'printer', 'mime', 'copies', 'max-attempts'],
                'status' => ['id'], 'work' => ['once', 'max-jobs', 'transport', 'printers', 'lease', 'timeout', 'lp'],
                'recover' => [], 'retry' => ['id'], 'resolve' => ['id', 'outcome', 'evidence', 'system-job-id'],
                'cancel' => ['id'], 'purge' => ['id'], 'prune' => [],
            ];
            if (!array_key_exists($command, $allowed)) {
                throw new \InvalidArgumentException('Unknown command.');
            }
            $options = self::options(array_slice($arguments, 2), array_merge(['state'], $allowed[$command]));
            $state = self::required($options, 'state');
            $queue = new Queue($state);
            if ($command === 'init') {
                self::output(['state' => 'ready']);
                return 0;
            }
            if ($command === 'recover' || $command === 'prune') {
                self::output(['changed' => $command === 'recover' ? $queue->recover() : $queue->pruneOrphans()]);
                return 0;
            }
            if ($command === 'status') {
                self::output($queue->get(self::required($options, 'id')));
                return 0;
            }
            $logger = new AuditLogger(rtrim($state, '/') . '/logs');
            if ($command === 'work') {
                return self::work($queue, $logger, $options);
            }
            $job = match ($command) {
                'submit' => $queue->enqueue(self::required($options, 'key'), new PrintRequest(
                    self::required($options, 'printer'), self::stdinDocument(), self::required($options, 'mime'),
                    self::number($options, 'copies', 1),
                ), self::number($options, 'max-attempts', 3)),
                'retry' => $queue->retry(self::required($options, 'id')),
                'cancel' => $queue->cancel(self::required($options, 'id')),
                'purge' => $queue->purgeDocument(self::required($options, 'id')),
                default => self::resolve($queue, $options),
            };
            $logger->record($job);
            self::output($job);
            return 0;
        } catch (\Throwable $error) {
            $code = match (true) {
                $error instanceof IdempotencyConflict => 'idempotency_conflict',
                $error instanceof \InvalidArgumentException => 'invalid_input',
                $error instanceof \OutOfBoundsException => 'not_found',
                default => 'operation_failed',
            };
            // Never print exception messages: PDO/process/path messages can disclose sensitive data.
            fwrite(STDERR, json_encode(['error' => $code], JSON_THROW_ON_ERROR) . "\n");
            return $code === 'operation_failed' ? 1 : 2;
        }
    }

    /** @param array<string, string> $options */
    private static function work(Queue $queue, AuditLogger $logger, array $options): int
    {
        $mode = $options['transport'] ?? 'fake';
        if (!in_array($mode, ['fake', 'cups'], true) || ($mode === 'cups' && !isset($options['printers']))) {
            throw new \InvalidArgumentException('Invalid transport or missing CUPS allowlist.');
        }
        $printers = explode(',', $options['printers'] ?? 'demo');
        $transport = $mode === 'cups'
            ? new CupsTransport($printers, $options['lp'] ?? '/usr/bin/lp', self::number($options, 'timeout', 10))
            : new FakeTransport($printers);
        $worker = new Worker($queue, $transport, self::number($options, 'lease', 45), $logger);
        $limit = isset($options['once']) ? 1 : self::number($options, 'max-jobs', 1);
        if (isset($options['once'], $options['max-jobs'])) {
            throw new \InvalidArgumentException('Choose once or max-jobs.');
        }
        $stop = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void { $stop = true; };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
        $count = 0;
        $exitCode = 0;
        while (!$stop && ($limit === 0 || $count < $limit)) {
            $job = $worker->runOnce();
            if ($job === null) {
                if ($limit !== 0) {
                    self::output(['state' => 'idle']);
                    break;
                }
                usleep(250_000);
                continue;
            }
            ++$count;
            self::output($job);
            if ($job->status === Status::Unknown) {
                return 3;
            }
            if ($job->status === Status::Failed) {
                $exitCode = 4;
            }
        }
        return $exitCode;
    }

    /** @param array<string, string> $options */
    private static function resolve(Queue $queue, array $options): Job
    {
        $outcome = self::required($options, 'outcome');
        if (!in_array($outcome, ['submitted', 'not-submitted'], true)) {
            throw new \InvalidArgumentException('Invalid reconciliation outcome.');
        }
        return $queue->resolveUnknown(self::required($options, 'id'), $outcome === 'submitted',
            self::required($options, 'evidence'), $options['system-job-id'] ?? null);
    }

    /** @param list<string> $arguments
     *  @param list<string> $allowed
     *  @return array<string, string> */
    private static function options(array $arguments, array $allowed): array
    {
        $options = [];
        for ($i = 0, $length = count($arguments); $i < $length; ++$i) {
            $argument = $arguments[$i];
            if (!str_starts_with($argument, '--')) {
                throw new \InvalidArgumentException('Options must start with --.');
            }
            $name = substr($argument, 2);
            if (!in_array($name, $allowed, true) || array_key_exists($name, $options)) {
                throw new \InvalidArgumentException('Unknown or duplicate option.');
            }
            if ($name === 'once') {
                $options[$name] = '1';
                continue;
            }
            $value = $arguments[++$i] ?? '';
            if ($value === '' || str_starts_with($value, '--') || strlen($value) > 4096) {
                throw new \InvalidArgumentException('Missing or invalid option value.');
            }
            $options[$name] = $value;
        }
        return $options;
    }

    /** @param array<string, string> $options */
    private static function required(array $options, string $name): string
    {
        return $options[$name] ?? throw new \InvalidArgumentException('Required option missing.');
    }

    /** @param array<string, string> $options */
    private static function number(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? (string) $default;
        if (preg_match('/\A[0-9]{1,8}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected an unsigned integer.');
        }
        return (int) $value;
    }

    private static function stdinDocument(): string
    {
        $bytes = stream_get_contents(STDIN, 10_485_761);
        if ($bytes === false || strlen($bytes) > 10_485_760) {
            throw new \InvalidArgumentException('Cannot read document or document exceeds 10 MiB.');
        }
        return $bytes;
    }

    /** @param array<string, int|string>|Job $value */
    private static function output(array|Job $value): void
    {
        fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
