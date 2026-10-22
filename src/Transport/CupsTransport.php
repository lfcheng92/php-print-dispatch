<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch\Transport;

use Lfcheng\PrintDispatch\FailureCode;
use Lfcheng\PrintDispatch\PrintRequest;

/** macOS/Linux CUPS only. It reports scheduler acceptance, never physical paper output. */
final class CupsTransport implements Transport
{
    /** @param list<string> $allowedPrinters */
    public function __construct(
        private readonly array $allowedPrinters,
        private readonly string $executable = '/usr/bin/lp',
        private readonly int $timeoutSeconds = 10,
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {
        if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            throw new \RuntimeException('CUPS adapter supports macOS and Linux only.');
        }
        if ($allowedPrinters === [] || $timeoutSeconds < 1 || $timeoutSeconds > 300
            || !str_starts_with($executable, '/') || str_contains($executable, "\0")) {
            throw new \InvalidArgumentException('An explicit printer allowlist, absolute executable and 1..300s timeout are required.');
        }
        foreach ($allowedPrinters as $printer) {
            PrintRequest::validatePrinter($printer);
        }
    }

    public function maximumRunSeconds(): int
    {
        return $this->timeoutSeconds + 1; // Includes process termination grace.
    }

    public function submit(Submission $submission): TransportResult
    {
        $job = $submission->job;
        if (!in_array($job->printer, $this->allowedPrinters, true)) {
            return TransportResult::notSubmitted(FailureCode::PrinterNotAllowed);
        }
        $result = $this->runner->run([
            $this->executable, '-h', 'localhost:631', '-d', $job->printer, '-n', (string) $job->copies,
            '-t', 'print-dispatch:' . $job->id, '-o', 'document-format=' . $job->mediaType, '--', $submission->documentPath,
        ], $this->timeoutSeconds);
        if (!$result->started) {
            return TransportResult::notSubmitted($result->failure ?? FailureCode::SpawnFailed);
        }
        if ($result->timedOut) {
            return TransportResult::unknown(FailureCode::Timeout);
        }
        if ($result->outputExceeded) {
            return TransportResult::unknown(FailureCode::OutputLimit);
        }
        // Even a nonzero lp exit can follow scheduler acceptance. Never blindly retry it.
        if ($result->exitCode !== 0) {
            return TransportResult::unknown(FailureCode::CommandFailed);
        }
        $printer = preg_quote($job->printer, '/');
        if (preg_match('/\Arequest id is (' . $printer . '-[0-9]{1,12}) \([0-9]+ file\(s\)\)\s*\z/D', $result->stdout, $match) !== 1) {
            return TransportResult::unknown(FailureCode::UnrecognizedResponse);
        }
        return TransportResult::submitted($match[1]);
    }
}
