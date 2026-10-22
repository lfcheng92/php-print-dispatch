<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/bin/print-dispatch'];
foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP syntax check.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $output);
        exit(1);
    }
}
echo 'Syntax OK: ' . count($files) . " files\n";
