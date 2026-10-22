<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lfcheng\PrintBridge\PrintRequest;
use Lfcheng\PrintBridge\Queue;
use Lfcheng\PrintBridge\Transport\FakeTransport;
use Lfcheng\PrintBridge\Worker;

// Creates a unique private directory under the local system temp directory.
$state = sys_get_temp_dir() . '/print-bridge-example-' . bin2hex(random_bytes(8));
$queue = new Queue($state);
$job = $queue->enqueue('synthetic-example-1', new PrintRequest('demo', "Synthetic example only\n", 'text/plain'));
echo json_encode($job, JSON_THROW_ON_ERROR) . "\n";
echo json_encode((new Worker($queue, new FakeTransport()))->runOnce(), JSON_THROW_ON_ERROR) . "\n";
// Result is simulated, not submitted; no OS job ID exists and direct purge is blocked.
// No printer was contacted. Remove this private synthetic temp directory after inspecting it.
echo 'Temporary synthetic state: ' . $state . "\n";
