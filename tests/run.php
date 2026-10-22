<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lfcheng\PrintBridge\AuditLogger;
use Lfcheng\PrintBridge\Claim;
use Lfcheng\PrintBridge\Clock;
use Lfcheng\PrintBridge\Exception\BridgeException;
use Lfcheng\PrintBridge\Exception\IdempotencyConflict;
use Lfcheng\PrintBridge\Exception\LeaseLost;
use Lfcheng\PrintBridge\FailureCode;
use Lfcheng\PrintBridge\PrintRequest;
use Lfcheng\PrintBridge\Queue;
use Lfcheng\PrintBridge\Status;
use Lfcheng\PrintBridge\Transport\CupsTransport;
use Lfcheng\PrintBridge\Transport\FakeTransport;
use Lfcheng\PrintBridge\Transport\ProcessRunner;
use Lfcheng\PrintBridge\Transport\Submission;
use Lfcheng\PrintBridge\Transport\Transport;
use Lfcheng\PrintBridge\Transport\TransportResult;
use Lfcheng\PrintBridge\Worker;

final class TestClock implements Clock
{
    public function __construct(public int $value = 1_000_000) {}
    public function now(): int { return $this->value; }
}

final class Suite
{
    public int $tests = 0;
    public int $assertions = 0;
    public int $failures = 0;
    public string $base;

    public function __construct()
    {
        $this->base = sys_get_temp_dir() . '/print-bridge-tests-' . bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
    }

    public function test(string $name, Closure $test): void
    {
        ++$this->tests;
        try {
            $test();
            echo 'PASS ' . $name . "\n";
        } catch (Throwable $error) {
            ++$this->failures;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error::class . ': ' . $error->getMessage() . "\n");
        }
    }

    public function same(mixed $expected, mixed $actual): void
    {
        ++$this->assertions;
        if ($expected !== $actual) {
            throw new RuntimeException('Values differ: ' . var_export($expected, true) . ' vs ' . var_export($actual, true));
        }
    }

    public function truth(bool $value): void { $this->same(true, $value); }

    public function throws(string $type, Closure $operation): void
    {
        ++$this->assertions;
        try {
            $operation();
        } catch (Throwable $error) {
            if ($error instanceof $type) {
                return;
            }
            throw new RuntimeException('Wrong exception: ' . $error::class, 0, $error);
        }
        throw new RuntimeException('Expected exception was not thrown: ' . $type);
    }

    public function path(): string { return $this->base . '/case-' . bin2hex(random_bytes(6)); }
    public function queue(?TestClock $clock = null, int $limit = 10_485_760): Queue { return new Queue($this->path(), $limit, $clock); }

    public function script(string $body): string
    {
        $path = $this->path();
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\ndeclare(strict_types=1);\n" . $body);
        chmod($path, 0700);
        return $path;
    }

    /** @return array{int,string,string} */
    public function command(array $command, string $input = ''): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot spawn test process.'); }
        if ($input !== '') { fwrite($pipes[0], $input); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + 15;
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('Test subprocess timeout.');
            }
            usleep(10_000);
        } while (true);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $closed = proc_close($process);
        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $out, $err];
    }

    public function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) { $this->remove($entry->getPathname()); }
        }
        rmdir($path);
    }
}

function request(string $bytes = "Synthetic document\n", string $printer = 'demo', int $copies = 1): PrintRequest
{
    return new PrintRequest($printer, $bytes, 'text/plain', $copies);
}

$s = new Suite();
$s->test('valid text and PDF requests', function () use ($s): void {
    $s->same(2, request('中文 synthetic', 'demo', 2)->copies);
    $s->same('application/pdf', (new PrintRequest('demo', '%PDF-1.7 synthetic'))->mediaType);
});
$s->test('reject unsafe printer names', function () use ($s): void {
    foreach (['-x', 'demo;touch x', '../demo', 'ipp://host', 'demo/name', "demo\n", ''] as $printer) {
        $s->throws(InvalidArgumentException::class, fn () => request('test', $printer));
    }
});
$s->test('reject unsupported content and copies', function () use ($s): void {
    foreach ([0, 100, -1] as $copies) { $s->throws(InvalidArgumentException::class, fn () => request('x', 'demo', $copies)); }
    foreach (['', "bad\0data", "\x1B@", "\xFF"] as $bytes) { $s->throws(InvalidArgumentException::class, fn () => request($bytes)); }
    $s->throws(InvalidArgumentException::class, fn () => new PrintRequest('demo', 'not pdf'));
    $s->throws(InvalidArgumentException::class, fn () => new PrintRequest('demo', 'x', 'image/png'));
});
$s->test('document and retry limits', function () use ($s): void {
    $q = $s->queue(limit: 4);
    $s->same(4, $q->enqueue('limit', request('1234'))->byteCount);
    $s->throws(InvalidArgumentException::class, fn () => $q->enqueue('too-large', request('12345')));
    foreach ([0, 11] as $n) { $s->throws(InvalidArgumentException::class, fn () => $q->enqueue('n', request('x'), $n)); }
    $s->throws(InvalidArgumentException::class, fn () => $s->queue(limit: 0));
});
$s->test('idempotent enqueue persists across connections', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path);
    $a = $q->enqueue('business-key', request());
    $b = (new Queue($path))->enqueue('business-key', request());
    $s->same($a->id, $b->id); $s->same(Status::Received, $b->status);
    $s->same(1, count(glob($path . '/spool/*.data')));
});
$s->test('same key with changed parameters conflicts', function () use ($s): void {
    $q = $s->queue(); $q->enqueue('unique', request());
    foreach ([request('different'), request(printer: 'other'), request(copies: 2)] as $r) {
        $s->throws(IdempotencyConflict::class, fn () => $q->enqueue('unique', $r));
    }
    $s->throws(IdempotencyConflict::class, fn () => $q->enqueue('unique', request(), 2));
});
$s->test('invalid keys and lookup IDs are rejected', function () use ($s): void {
    $q = $s->queue();
    foreach (['', 'space key', str_repeat('x', 129), "x\n"] as $key) {
        $s->throws(InvalidArgumentException::class, fn () => $q->enqueue($key, request()));
    }
    $s->throws(InvalidArgumentException::class, fn () => $q->get('../secret'));
    $s->throws(OutOfBoundsException::class, fn () => $q->get(str_repeat('0', 32)));
});
$s->test('private file permissions and no symlink state', function () use ($s): void {
    $path = $s->path(); new Queue($path);
    $s->same(0, fileperms($path) & 0077); $s->same(0, fileperms($path . '/queue.sqlite') & 0077);
    $link = $s->path(); symlink($path, $link);
    $s->throws(BridgeException::class, fn () => new Queue($link));
    $public = $s->path(); mkdir($public, 0755); chmod($public, 0755);
    $s->throws(BridgeException::class, fn () => new Queue($public));
});
$s->test('reject nonlocal and dot-segment paths', function () use ($s): void {
    foreach (['relative', 'https://example.invalid/state', '/tmp/../state', '/tmp/./state', '/'] as $path) {
        $s->throws(InvalidArgumentException::class, fn () => new Queue($path));
    }
});
$s->test('reject database symlink', function () use ($s): void {
    $path = $s->path(); mkdir($path, 0700);
    $target = $s->path(); file_put_contents($target, 'synthetic');
    symlink($target, $path . '/queue.sqlite');
    $s->throws(BridgeException::class, fn () => new Queue($path));
    $s->same('synthetic', file_get_contents($target));
});
$s->test('fake worker simulates once and never claims simulated jobs', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('one', request()); $fake = new FakeTransport();
    $worker = new Worker($q, $fake); $result = $worker->runOnce();
    $s->same(Status::Simulated, $result->status); $s->same(1, $result->attempts);
    $s->same('unverified', $result->jsonSerialize()['physical_output']);
    $s->same(null, $worker->runOnce()); $s->same(1, count($fake->submissions));
    $s->same($job->id, $q->enqueue('one', request())->id);
});
$s->test('worker allowlist prevents unwanted destination', function () use ($s): void {
    $q = $s->queue(); $q->enqueue('other', request(printer: 'unconfigured')); $fake = new FakeTransport();
    $result = (new Worker($q, $fake))->runOnce();
    $s->same(Status::Failed, $result->status); $s->same(FailureCode::PrinterNotAllowed, $result->lastError);
    $s->same(0, count($fake->submissions));
});
$s->test('lease excludes concurrent claimants and fences wrong token', function () use ($s): void {
    $q = $s->queue(); $q->enqueue('lock', request()); $claim = $q->claim(10);
    $s->same(null, $q->claim(10));
    $s->throws(LeaseLost::class, fn () => $q->beginSubmission(new Claim($claim->job, 'wrong'), 10));
    $q->beginSubmission($claim, 10);
    $s->throws(LeaseLost::class, fn () => $q->finish(new Claim($claim->job, 'wrong'), TransportResult::submitted('demo-1')));
    $s->same(Status::Submitted, $q->finish($claim, TransportResult::submitted('demo-1'))->status);
});
$s->test('expired pre-submit lease is recoverable without an attempt', function () use ($s): void {
    $clock = new TestClock(); $q = $s->queue($clock); $a = $q->enqueue('reclaim', request()); $old = $q->claim(5);
    $clock->value += 5; $s->same(1, $q->recover());
    $s->same(Status::Received, $q->get($a->id)->status); $s->same(0, $q->get($a->id)->attempts);
    $s->throws(LeaseLost::class, fn () => $q->beginSubmission($old, 5));
    $s->truth($q->claim(5)->token !== $old->token);
});
$s->test('expired submission becomes unknown and never auto retries', function () use ($s): void {
    $clock = new TestClock(); $q = $s->queue($clock); $job = $q->enqueue('uncertain', request()); $claim = $q->claim(5);
    $q->beginSubmission($claim, 5); $clock->value += 6;
    $s->same(1, $q->recover()); $s->same(Status::Unknown, $q->get($job->id)->status);
    $s->same(null, $q->claim(5));
    $s->throws(BridgeException::class, fn () => $q->retry($job->id));
    $s->throws(LeaseLost::class, fn () => $q->finish($claim, TransportResult::submitted('demo-1')));
});
$s->test('a late adapter result cannot override lease expiry', function () use ($s): void {
    $clock = new TestClock(); $q = $s->queue($clock); $q->enqueue('late', request());
    $transport = new class($clock) implements Transport {
        public function __construct(private TestClock $clock) {}
        public function maximumRunSeconds(): int { return 0; }
        public function submit(Submission $s): TransportResult { $this->clock->value += 10; return TransportResult::submitted('demo-1'); }
    };
    $s->same(Status::Unknown, (new Worker($q, $transport, 5))->runOnce()->status);
});
$s->test('safe retry uses backoff and stops at configured attempts', function () use ($s): void {
    $clock = new TestClock(); $q = $s->queue($clock); $job = $q->enqueue('retry', request(), 2);
    $fake = new FakeTransport(['demo'], TransportResult::notSubmitted(FailureCode::SafeRejection, true));
    $w = new Worker($q, $fake); $a = $w->runOnce();
    $s->same(Status::RetryWait, $a->status); $s->same($clock->value + 10, $a->availableAt);
    $s->same(null, $w->runOnce()); $clock->value += 10;
    $s->same(Status::Failed, $w->runOnce()->status); $s->same(2, $q->get($job->id)->attempts);
    $s->same(null, $w->runOnce());
});
$s->test('manual retry adds one bounded attempt and preserves attempt count', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('manual', request(), 1);
    (new Worker($q, new FakeTransport(['demo'], TransportResult::notSubmitted(FailureCode::SafeRejection, true))))->runOnce();
    $s->same(2, $q->retry($job->id)->maxAttempts);
    $s->same(Status::Simulated, (new Worker($q, new FakeTransport()))->runOnce()->status);
    $s->same(2, $q->get($job->id)->attempts);
});
$s->test('transport exceptions are unknown and error messages do not leak', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $q->enqueue('SYNTHETIC_SECRET_KEY', request('SYNTHETIC_SECRET_BODY'));
    $transport = new class implements Transport {
        public function maximumRunSeconds(): int { return 0; }
        public function submit(Submission $s): TransportResult { throw new RuntimeException('SYNTHETIC_SECRET_EXCEPTION'); }
    };
    $logger = new AuditLogger($path . '/logs'); $job = (new Worker($q, $transport, logger: $logger))->runOnce();
    $s->same(Status::Unknown, $job->status); $s->same(FailureCode::TransportException, $job->lastError);
    $log = file_get_contents($path . '/logs/events.jsonl');
    $s->truth(!str_contains($log, 'SYNTHETIC_SECRET'));
    $s->truth(!str_contains(json_encode($job), 'SYNTHETIC_SECRET'));
    $s->same(['time','event','job_id','status','attempts','error_code'], array_keys(json_decode(trim($log), true, flags: JSON_THROW_ON_ERROR)));
});
$s->test('manual unknown reconciliation requires evidence and explicit retry', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('resolve', request());
    (new Worker($q, new FakeTransport(['demo'], TransportResult::unknown(FailureCode::Timeout))))->runOnce();
    $s->throws(InvalidArgumentException::class, fn () => $q->resolveUnknown($job->id, false, ''));
    $s->same(Status::Failed, $q->resolveUnknown($job->id, false, 'LOCAL-CHECK-1')->status);
    $s->same(null, (new Worker($q, new FakeTransport()))->runOnce());
    $s->same(Status::Received, $q->retry($job->id)->status);
});
$s->test('reconciliation of confirmed accepted job prevents retry', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('accepted', request());
    (new Worker($q, new FakeTransport(['demo'], TransportResult::unknown(FailureCode::Timeout))))->runOnce();
    $s->throws(InvalidArgumentException::class, fn () => $q->resolveUnknown($job->id, true, 'ticket-1'));
    $s->same('demo-42', $q->resolveUnknown($job->id, true, 'ticket-1', 'demo-42')->systemJobId);
    $s->throws(BridgeException::class, fn () => $q->retry($job->id));
    $s->throws(BridgeException::class, fn () => $q->resolveUnknown($job->id, true, 'ticket-1', 'demo-42'));
});
$s->test('cancellation and purge preserve idempotency tombstones', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('cancel', request());
    $s->same(Status::Cancelled, $q->cancel($job->id)->status); $s->same(null, $q->claim(5));
    $s->truth($q->purgeDocument($job->id)->documentPurged);
    $s->same(Status::Cancelled, $q->enqueue('cancel', request())->status);
    $s->throws(BridgeException::class, fn () => $q->retry($job->id));
});
$s->test('purge submitted payload is repeatable and active payload cannot purge', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('purge', request());
    $s->throws(BridgeException::class, fn () => $q->purgeDocument($job->id));
    $lp = $s->script('echo "request id is demo-1 (1 file(s))\\n";');
    (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce();
    $s->truth($q->purgeDocument($job->id)->documentPurged); $s->truth($q->purgeDocument($job->id)->documentPurged);
    $s->same(Status::Submitted, $q->enqueue('purge', request())->status);
});
$s->test('active and uncertain jobs cannot be cancelled', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('busy', request()); $q->claim(5);
    $s->throws(BridgeException::class, fn () => $q->cancel($job->id));
});
$s->test('spool tampering fails before transport execution', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $job = $q->enqueue('corrupt', request());
    file_put_contents($path . '/spool/' . $job->id . '.data', 'CORRUPTED');
    $fake = new FakeTransport(); $result = (new Worker($q, $fake))->runOnce();
    $s->same(Status::Failed, $result->status); $s->same(FailureCode::SpoolInvalid, $result->lastError);
    $s->same(0, $result->attempts); $s->same(0, count($fake->submissions));
    $s->throws(BridgeException::class, fn () => $q->retry($job->id));
});
$s->test('spool symlink is never passed to transport', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $job = $q->enqueue('symlink', request());
    $file = $path . '/spool/' . $job->id . '.data'; unlink($file);
    $target = $s->path(); file_put_contents($target, 'synthetic'); symlink($target, $file);
    $fake = new FakeTransport(); $s->same(Status::Failed, (new Worker($q, $fake))->runOnce()->status);
    $s->same(0, count($fake->submissions)); $s->same('synthetic', file_get_contents($target));
});
$s->test('orphan pruning skips live jobs, fresh files and symlinks', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $job = $q->enqueue('live', request());
    $old = $path . '/spool/' . str_repeat('1', 32) . '.data'; file_put_contents($old, 'orphan'); chmod($old, 0600); touch($old, time()-5000);
    $fresh = $path . '/spool/' . str_repeat('2', 32) . '.data'; file_put_contents($fresh, 'fresh'); chmod($fresh, 0600);
    $link = $path . '/spool/' . str_repeat('3', 32) . '.data'; symlink($fresh, $link);
    $s->same(1, $q->pruneOrphans()); $s->truth(is_file($q->documentPath($job))); $s->truth(is_file($fresh)); $s->truth(is_link($link));
    $s->throws(InvalidArgumentException::class, fn () => $q->pruneOrphans(1));
});
$s->test('worker requires bounded transport and sufficient lease', function () use ($s): void {
    $s->throws(InvalidArgumentException::class, fn () => new Worker($s->queue(), new CupsTransport(['demo']), 10));
    $s->throws(InvalidArgumentException::class, fn () => $s->queue()->claim(4));
    $s->throws(InvalidArgumentException::class, fn () => new FakeTransport([]));
    $s->throws(InvalidArgumentException::class, fn () => new CupsTransport(['-unsafe']));
});
$s->test('CUPS isolated command argv and locale match expected values', function () use ($s): void {
    $capture = $s->path();
    $lp = $s->script('file_put_contents(' . var_export($capture, true) . ', json_encode([$argv, getenv("LC_ALL"), getenv("PRINT_BRIDGE_TEST_SECRET")], JSON_THROW_ON_ERROR)); echo "request id is demo-42 (1 file(s))\\n";');
    putenv('PRINT_BRIDGE_TEST_SECRET=SYNTHETIC_PARENT_SECRET');
    $q = $s->queue(); $job = $q->enqueue('cups', request(copies: 2));
    $result = (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce();
    putenv('PRINT_BRIDGE_TEST_SECRET');
    $s->same(Status::Submitted, $result->status); $s->same('demo-42', $result->systemJobId);
    [$args, $locale, $secret] = json_decode(file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
    $s->same([$lp, '-h', 'localhost:631', '-d', 'demo', '-n', '2', '-t', 'print-bridge:' . $job->id,
        '-o', 'document-format=text/plain', '--', $q->documentPath($job)], $args);
    $s->same('C', $locale); $s->same(false, $secret);
});
$s->test('CUPS rejects non-allowlisted printer without executing command', function () use ($s): void {
    $capture = $s->path(); $lp = $s->script('file_put_contents(' . var_export($capture, true) . ', "called");');
    $q = $s->queue(); $q->enqueue('blocked', request(printer: 'other'));
    $s->same(Status::Failed, (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce()->status);
    $s->same(false, file_exists($capture));
});
$s->test('CUPS missing executable is a proven not-submitted failure', function () use ($s): void {
    $q = $s->queue(); $q->enqueue('missing', request());
    $job = (new Worker($q, new CupsTransport(['demo'], $s->path())))->runOnce();
    $s->same(Status::Failed, $job->status); $s->same(FailureCode::MissingExecutable, $job->lastError); $s->truth($job->retrySafe);
});
$s->test('CUPS nonzero exit is unknown and stderr is discarded', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $q->enqueue('nonzero', request());
    $lp = $s->script('fwrite(STDERR, "SYNTHETIC_SECRET_CUPS_ERROR"); exit(1);');
    $job = (new Worker($q, new CupsTransport(['demo'], $lp), logger: new AuditLogger($path . '/logs')))->runOnce();
    $s->same(Status::Unknown, $job->status); $s->same(FailureCode::CommandFailed, $job->lastError);
    $s->truth(!str_contains(file_get_contents($path . '/logs/events.jsonl'), 'SYNTHETIC_SECRET'));
});
$s->test('CUPS success without recognized request ID is unknown', function () use ($s): void {
    foreach (['', 'request id is other-1 (1 file(s))', 'demo-1', 'request id is demo-1 (1 file(s)) trailing'] as $out) {
        $lp = $s->script('echo ' . var_export($out, true) . ';');
        $q = $s->queue(); $q->enqueue('parse', request());
        $job = (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce();
        $s->same(Status::Unknown, $job->status); $s->same(FailureCode::UnrecognizedResponse, $job->lastError);
    }
});
$s->test('CUPS timeout terminates fake command and stays unknown', function () use ($s): void {
    $lp = $s->script('usleep(3000000); echo "request id is demo-1 (1 file(s))\\n";');
    $q = $s->queue(); $q->enqueue('timeout', request()); $start = microtime(true);
    $job = (new Worker($q, new CupsTransport(['demo'], $lp, 1)))->runOnce();
    $s->same(Status::Unknown, $job->status); $s->same(FailureCode::Timeout, $job->lastError);
    $s->truth(microtime(true)-$start < 3); $s->same(null, $q->claim(5));
});
$s->test('unbounded command output is limited and unknown', function () use ($s): void {
    $lp = $s->script('for ($i=0; $i<1000; ++$i) { fwrite(STDOUT, str_repeat("x",1024)); }');
    $q = $s->queue(); $q->enqueue('overflow', request());
    $job = (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce();
    $s->same(Status::Unknown, $job->status); $s->same(FailureCode::OutputLimit, $job->lastError);
});
$s->test('process runner passes shell metacharacters literally', function () use ($s): void {
    $capture = $s->path(); $marker = $s->path();
    $command = $s->script('file_put_contents(' . var_export($capture, true) . ', json_encode($argv));');
    $payload = '$(touch ' . $marker . '); echo injected';
    $result = (new ProcessRunner())->run([$command, $payload], 2);
    $s->same(0, $result->exitCode); $s->same($payload, json_decode(file_get_contents($capture), true)[1]);
    $s->same(false, file_exists($marker));
});
$s->test('process runner validates NUL, timeout and executable', function () use ($s): void {
    $runner = new ProcessRunner();
    $s->throws(InvalidArgumentException::class, fn () => $runner->run([PHP_BINARY, "\0"], 1));
    $s->throws(InvalidArgumentException::class, fn () => $runner->run([PHP_BINARY], 0));
    $s->same(false, $runner->run(['php'], 1)->started);
});
$s->test('CLI separate-process submit, dedupe, status and fake work integration', function () use ($s): void {
    $root = $s->path(); $bin = dirname(__DIR__) . '/bin/print-bridge';
    $submit = [PHP_BINARY, $bin, 'submit', '--state', $root, '--key', 'cli-one', '--printer', 'demo', '--mime', 'text/plain'];
    [$exit, $out, $err] = $s->command($submit, "Synthetic CLI\n"); $s->same(0, $exit); $s->same('', $err);
    $job = json_decode($out, true, flags: JSON_THROW_ON_ERROR); $s->same('received', $job['status']);
    [$exit, $out] = $s->command($submit, "Synthetic CLI\n"); $s->same(0, $exit); $s->same($job['id'], json_decode($out, true)['id']);
    [$exit, $out] = $s->command([PHP_BINARY, $bin, 'work', '--state', $root, '--once', '--transport', 'fake']);
    $s->same(0, $exit); $s->same('simulated', json_decode($out, true)['status']);
    [$exit, $out] = $s->command([PHP_BINARY, $bin, 'status', '--state', $root, '--id', $job['id']]);
    $s->same(0, $exit); $s->same('unverified', json_decode($out, true)['physical_output']);
    [$exit, $out] = $s->command([PHP_BINARY, $bin, 'work', '--state', $root, '--once', '--transport', 'fake']);
    $s->same(0, $exit); $s->same('idle', json_decode($out, true)['state']);
});
$s->test('CLI rejects malformed options, files, URLs, conflicts and missing allowlist', function () use ($s): void {
    $root = $s->path(); $bin = dirname(__DIR__) . '/bin/print-bridge';
    foreach ([['status','--id','../secret'], ['submit','--file','/etc/passwd'], ['submit','--url','https://example.invalid'],
        ['work','--transport','cups'], ['work','--once','--once'], ['work','--transport','fake','--max-jobs','-1'], ['bogus']] as $args) {
        [$exit, $out, $err] = $s->command([PHP_BINARY, $bin, ...$args, '--state', $root]);
        $s->same(2, $exit); $s->same('', $out); $s->same('invalid_input', json_decode($err, true)['error']);
    }
    $args = [PHP_BINARY, $bin, 'submit', '--state', $root, '--key', 'conflict', '--printer', 'demo', '--mime', 'text/plain'];
    $s->command($args, 'one'); [$exit, , $err] = $s->command($args, 'two');
    $s->same(2, $exit); $s->same('idempotency_conflict', json_decode($err, true)['error']);
});
$s->test('CLI CUPS uncertainty has dedicated exit code without raw command output', function () use ($s): void {
    $root = $s->path(); $q = new Queue($root); $q->enqueue('cli-unknown', request());
    $lp = $s->script('fwrite(STDERR, "SYNTHETIC_SECRET"); exit(1);');
    [$exit, $out, $err] = $s->command([PHP_BINARY, dirname(__DIR__) . '/bin/print-bridge', 'work', '--state', $root,
        '--once', '--transport', 'cups', '--printers', 'demo', '--lp', $lp]);
    $s->same(3, $exit); $s->same('unknown', json_decode($out, true)['status']); $s->same('', $err);
    $s->truth(!str_contains($out, 'SYNTHETIC_SECRET'));
});
$s->test('real subprocess death before and after submission marker recovers conservatively', function () use ($s): void {
    foreach ([false, true] as $afterMarker) {
        $root = $s->path(); $clock = new TestClock(time()); $q = new Queue($root, clock: $clock);
        $job = $q->enqueue('crash', request());
        $script = $s->script('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$q=new \\Lfcheng\\PrintBridge\\Queue(' . var_export($root, true) . '); $c=$q->claim(5);'
            . ($afterMarker ? '$q->beginSubmission($c,5);' : '') . 'echo "READY\\n"; fflush(STDOUT); sleep(30);');
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        stream_set_timeout($pipes[1], 5); $s->same("READY\n", fgets($pipes[1]));
        $s->truth(proc_terminate($process, 9)); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $clock->value = time() + 10; $s->same(1, $q->recover());
        $s->same($afterMarker ? Status::Unknown : Status::Received, $q->get($job->id)->status);
        $s->same($afterMarker ? 1 : 0, $q->get($job->id)->attempts);
    }
});
$s->test('concurrent producers share one idempotent durable job', function () use ($s): void {
    $root = $s->path(); new Queue($root);
    $script = $s->script('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
        . '$q=new \\Lfcheng\\PrintBridge\\Queue(' . var_export($root, true) . ');'
        . 'echo $q->enqueue("race",new \\Lfcheng\\PrintBridge\\PrintRequest("demo","synthetic","text/plain"))->id;');
    $children = [];
    for ($i=0; $i<4; ++$i) { $p = proc_open([PHP_BINARY,$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $children[]=[$p,$pipes]; }
    $ids = [];
    foreach ($children as [$p,$pipes]) {
        $ids[] = stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $s->same(0, proc_close($p)); $s->same('', $err);
    }
    $s->same(1, count(array_unique($ids))); $s->same(1, count(glob($root . '/spool/*.data')));
});
$s->test('concurrent workers submit a single queued job only once', function () use ($s): void {
    $root = $s->path(); $q = new Queue($root); $job = $q->enqueue('worker-race', request());
    $script = $s->script('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
        . '$q=new \\Lfcheng\\PrintBridge\\Queue(' . var_export($root,true) . ');'
        . '$j=(new \\Lfcheng\\PrintBridge\\Worker($q,new \\Lfcheng\\PrintBridge\\Transport\\FakeTransport()))->runOnce();'
        . 'echo $j?->id ?? "idle";');
    $children=[]; for ($i=0;$i<4;++$i) { $p=proc_open([PHP_BINARY,$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes]; }
    $ids=[]; foreach ($children as [$p,$pipes]) {
        $ids[]=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);
        $s->same(0,proc_close($p));$s->same('',$err);
    }
    $s->same(1,count(array_filter($ids, fn($id)=>$id===$job->id)));
    $s->same(1,$q->get($job->id)->attempts); $s->same(Status::Simulated,$q->get($job->id)->status);
});

$s->test('truncated stdout can never be accepted as a complete receipt', function () use ($s): void {
    $lp = $s->script('echo "request id is demo-1 (1 file(s))" . str_repeat(" ",17000) . "unexpected";');
    $q = $s->queue(); $q->enqueue('truncated', request());
    $job = (new Worker($q, new CupsTransport(['demo'], $lp)))->runOnce();
    $s->same(Status::Unknown, $job->status); $s->same(FailureCode::OutputLimit, $job->lastError);
});
$s->test('audit failure after submission never permits duplicate processing', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path); $job = $q->enqueue('log-failure', request());
    $logger = new AuditLogger($path . '/logs'); chmod($path . '/logs/events.jsonl', 0644);
    $calls = $s->path();
    $lp = $s->script('file_put_contents(' . var_export($calls, true) . ', "call\\n", FILE_APPEND); echo "request id is demo-1 (1 file(s))\\n";');
    $worker = new Worker($q, new CupsTransport(['demo'], $lp), logger: $logger);
    $s->throws(BridgeException::class, fn () => $worker->runOnce());
    $s->same(Status::Submitted, $q->get($job->id)->status); $s->same(null, $worker->runOnce());
    $s->same("call\n", file_get_contents($calls));
});
$s->test('newer schema is rejected without changing its version', function () use ($s): void {
    $path = $s->path(); $q = new Queue($path);
    $db = new PDO('sqlite:' . $path . '/queue.sqlite'); $db->exec('PRAGMA user_version=99');
    $s->throws(BridgeException::class, fn () => new Queue($path));
    $s->same(99, (int) $db->query('PRAGMA user_version')->fetchColumn());
});
$s->test('empty commands and invalid system IDs fail explicitly', function () use ($s): void {
    $s->throws(InvalidArgumentException::class, fn () => (new ProcessRunner())->run([], 1));
    foreach (['', 'demo', "demo-1\n", 'demo;bad-1'] as $id) {
        $s->throws(InvalidArgumentException::class, fn () => TransportResult::submitted($id));
    }
});
$s->test('concurrent first-open initialization does not overwrite queue state', function () use ($s): void {
    for ($round = 0; $round < 5; ++$round) {
        $root = $s->path();
        $script = $s->script('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$q=new \\Lfcheng\\PrintBridge\\Queue(' . var_export($root,true) . ');'
            . 'echo $q->enqueue("first-open",new \\Lfcheng\\PrintBridge\\PrintRequest("demo","synthetic","text/plain"))->id;');
        $children=[]; for ($i=0;$i<4;++$i) { $p=proc_open([PHP_BINARY,$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes]; }
        $ids=[]; foreach ($children as [$p,$pipes]) {
            $ids[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $exit=proc_close($p);$s->same('',$err);$s->same(0,$exit);
        }
        $s->same(1,count(array_unique($ids)));$s->same(1,count(glob($root.'/spool/*.data')));
    }
});

$s->test('CLI requires transport even when CUPS-specific arguments are present', function () use ($s): void {
    $root = $s->path(); $q = new Queue($root); $job = $q->enqueue('explicit-mode', request(printer: 'office'));
    $marker = $s->path();
    $lp = $s->script('file_put_contents(' . var_export($marker, true) . ', "called"); echo "request id is office-1 (1 file(s))\\n";');
    $bin = dirname(__DIR__) . '/bin/print-bridge';
    foreach ([[], ['--printers','office','--lp',$lp], ['--printers','office','--timeout','1']] as $extra) {
        [$exit,$out,$err] = $s->command([PHP_BINARY,$bin,'work','--state',$root,'--once',...$extra]);
        $s->same(2,$exit); $s->same('',$out); $s->same('invalid_input',json_decode($err,true)['error']);
        $s->same(Status::Received,$q->get($job->id)->status); $s->same(0,$q->get($job->id)->attempts);
    }
    $s->same(false,file_exists($marker)); $s->truth(is_file($q->documentPath($job)));
});
$s->test('CLI rejects fake and CUPS-only option mixtures without claiming work', function () use ($s): void {
    $root = $s->path(); $q = new Queue($root); $job = $q->enqueue('mixed-mode', request(printer: 'office'));
    $marker = $s->path(); $lp = $s->script('file_put_contents(' . var_export($marker,true) . ', "called");');
    foreach ([['--lp',$lp], ['--timeout','1'], ['--lp',$lp,'--timeout','1']] as $extra) {
        [$exit,$out,$err] = $s->command([PHP_BINARY,dirname(__DIR__).'/bin/print-bridge','work','--state',$root,
            '--once','--transport','fake','--printers','office',...$extra]);
        $s->same(2,$exit); $s->same('',$out); $s->same('invalid_input',json_decode($err,true)['error']);
        $s->same(Status::Received,$q->get($job->id)->status); $s->same(0,$q->get($job->id)->attempts);
    }
    $s->same(false,file_exists($marker));
});
$s->test('simulation is persistent, has no OS receipt and cannot directly purge payload', function () use ($s): void {
    $root = $s->path(); $q = new Queue($root); $job = $q->enqueue('simulation-boundary', request(printer: 'office'));
    $result = (new Worker($q,new FakeTransport(['office'])))->runOnce();
    $s->same(Status::Simulated,$result->status); $s->same(null,$result->systemJobId);
    $s->same(true,$result->jsonSerialize()['simulated']); $s->same('unverified',$result->jsonSerialize()['physical_output']);
    $s->same(Status::Simulated,(new Queue($root))->get($job->id)->status);
    $s->same(Status::Simulated,$q->enqueue('simulation-boundary',request(printer:'office'))->status);
    $s->throws(BridgeException::class,fn()=>$q->purgeDocument($job->id));
    $s->truth(is_file($q->documentPath($job))); $s->same(false,$q->get($job->id)->documentPurged);
    $lp = $s->script('throw new RuntimeException("CUPS must not run without explicit requeue");');
    $s->same(null,(new Worker($q,new CupsTransport(['office'],$lp)))->runOnce());
});
$s->test('simulated real-queue job needs explicit retry before CUPS may claim it', function () use ($s): void {
    $q = $s->queue(); $job = $q->enqueue('explicit-handoff',request(printer:'office'));
    (new Worker($q,new FakeTransport(['office'])))->runOnce();
    $calls = $s->path();
    $lp = $s->script('file_put_contents(' . var_export($calls,true) . ', "call\\n", FILE_APPEND); echo "request id is office-7 (1 file(s))\\n";');
    $cups = new Worker($q,new CupsTransport(['office'],$lp));
    $s->same(null,$cups->runOnce()); $s->same(false,file_exists($calls));
    $s->same(Status::Received,$q->retry($job->id)->status);
    $result = $cups->runOnce();
    $s->same(Status::Submitted,$result->status); $s->same(false,$result->jsonSerialize()['simulated']);
    $s->same('office-7',$result->systemJobId); $s->same(2,$result->attempts);
    $s->same("call\n",file_get_contents($calls)); $s->same(null,$cups->runOnce());
});
$s->test('FakeTransport refuses forged submitted outcomes and simulation requires explicit cancellation to discard', function () use ($s): void {
    $s->throws(InvalidArgumentException::class,fn()=>new FakeTransport(['demo'],TransportResult::submitted('demo-1')));
    $q = $s->queue(); $job = $q->enqueue('discard-simulation',request());
    (new Worker($q,new FakeTransport()))->runOnce();
    $s->throws(BridgeException::class,fn()=>$q->purgeDocument($job->id));
    $s->same(Status::Cancelled,$q->cancel($job->id)->status);
    $s->truth($q->purgeDocument($job->id)->documentPurged);
});

$s->test('CLI reports unavailable arguments without warnings when register_argc_argv is off', function () use ($s): void {
    [$exit,$out,$err] = $s->command([PHP_BINARY,'-d','register_argc_argv=0',dirname(__DIR__).'/bin/print-bridge','help']);
    $s->same(1,$exit); $s->same('',$out);
    $s->same("CLI arguments unavailable. Enable register_argc_argv.\n",$err);
});

$s->remove($s->base);
echo sprintf("Tests: %d, assertions: %d, failures: %d\n", $s->tests, $s->assertions, $s->failures);
exit($s->failures === 0 ? 0 : 1);
