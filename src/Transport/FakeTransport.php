<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge\Transport;

use Lfcheng\PrintBridge\FailureCode;
use Lfcheng\PrintBridge\PrintRequest;

/** No OS printing; useful for local integration and deliberate simulation. */
final class FakeTransport implements Transport
{
    /** @var list<Submission> */
    public array $submissions = [];

    /** @param list<string> $allowedPrinters */
    public function __construct(private readonly array $allowedPrinters = ['demo'], private readonly ?TransportResult $result = null)
    {
        if ($allowedPrinters === []) {
            throw new \InvalidArgumentException('Printer allowlist is required.');
        }
        foreach ($allowedPrinters as $printer) {
            PrintRequest::validatePrinter($printer);
        }
    }

    public function maximumRunSeconds(): int
    {
        return 0;
    }

    public function submit(Submission $submission): TransportResult
    {
        if (!in_array($submission->job->printer, $this->allowedPrinters, true)) {
            return TransportResult::notSubmitted(FailureCode::PrinterNotAllowed);
        }
        $this->submissions[] = $submission;
        return $this->result ?? TransportResult::submitted('fake-' . (string) hexdec(substr($submission->job->id, 0, 7)));
    }
}
