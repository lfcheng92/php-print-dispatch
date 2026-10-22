<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch;

use Lfcheng\PrintDispatch\Exception\BridgeException;
use Lfcheng\PrintDispatch\Exception\LeaseLost;
use Lfcheng\PrintDispatch\Transport\Submission;
use Lfcheng\PrintDispatch\Transport\Transport;
use Lfcheng\PrintDispatch\Transport\TransportResult;

final class Worker
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Transport $transport,
        private readonly int $leaseSeconds = 45,
        private readonly ?AuditLogger $logger = null,
    ) {
        if ($leaseSeconds < 5 || $leaseSeconds > 3600 || $transport->maximumRunSeconds() < 0
            || $leaseSeconds < $transport->maximumRunSeconds() + 5) {
            throw new \InvalidArgumentException('Lease must exceed the bounded transport runtime by at least five seconds.');
        }
    }

    public function runOnce(): ?Job
    {
        $claim = $this->queue->claim($this->leaseSeconds);
        if ($claim === null) {
            return null;
        }
        try {
            try {
                $path = $this->queue->documentPath($claim->job);
            } catch (BridgeException) {
                $job = $this->queue->failBeforeSubmission($claim);
                $this->logger?->record($job);
                return $job;
            }
            $job = $this->queue->beginSubmission($claim, $this->leaseSeconds);
            try {
                $result = $this->transport->submit(new Submission($job, $path));
            } catch (\Throwable) {
                // The adapter may have submitted before throwing. Its message may be sensitive.
                $result = TransportResult::unknown(FailureCode::TransportException);
            }
            $job = $this->queue->finish($claim, $result);
        } catch (LeaseLost) {
            $this->queue->recover();
            $job = $this->queue->get($claim->job->id);
        }
        // Failure here never rolls back the durable result or causes a resubmission.
        $this->logger?->record($job);
        return $job;
    }
}
