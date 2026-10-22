<?php
declare(strict_types=1);

// Offline consumer test: archive, inspect, extract, copy-install, hide source, execute library + bin.
$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/print-bridge-consumer-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$composerPath = getenv('PRINT_BRIDGE_COMPOSER') ?: 'composer';
$composer = is_file($composerPath) ? [PHP_BINARY, $composerPath] : [$composerPath];
$environment = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LC_ALL' => 'C',
    'COMPOSER_HOME' => $base . '/home', 'COMPOSER_CACHE_DIR' => $base . '/cache',
    'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ROOT_VERSION' => 'dev-main',
    'TMPDIR' => $base, 'TMP' => $base, 'TEMP' => $base,
];

function run(array $command, string $directory, array $environment, string $stdin = ''): string
{
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $directory, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run consumer check command.'); }
    if ($stdin !== '') { fwrite($pipes[0], $stdin); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Consumer command failed: ' . $out . $err); }
    return $out;
}

function removeTree(string $path): void
{
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) { removeTree($entry->getPathname()); }
    }
    rmdir($path);
}

try {
    mkdir($base . '/archives', 0700);
    run([...$composer, 'archive', '--format=zip', '--dir=' . $base . '/archives', '--file=print-bridge', '--no-interaction', '--no-plugins', '--no-scripts'], $root, $environment);
    $archive = $base . '/archives/print-bridge.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot inspect source archive.'); }
    $required = ['composer.json', 'src/Queue.php', 'bin/print-bridge', 'README.md', 'README.en.md', 'SECURITY.md', 'LICENSE'];
    foreach ($required as $entry) {
        if ($zip->locateName($entry) === false) { throw new RuntimeException('Missing archive entry: ' . $entry); }
    }
    for ($i=0; $i<$zip->numFiles; ++$i) {
        $name = $zip->getNameIndex($i);
        if (preg_match('~(?:^|/)(?:vendor|var|build|\.git|\.phpstan|tests|tools|\.github)(?:/|$)|(?:^|/)(?:\.env[^/]*|auth\.json)$|\.(?:sqlite[^/]*|db|log|jsonl|pem|key|phar)$~i', $name)) {
            throw new RuntimeException('Forbidden source archive entry: ' . $name);
        }
        if (str_starts_with($name, '/') || str_contains($name, '../')) { throw new RuntimeException('Unsafe archive path.'); }
        $content = $zip->getFromIndex($i);
        if (is_string($content) && (preg_match('~/(?:Users|home)/[^/[:space:]]+/~', $content) === 1 || str_contains($content, 'SYNTHETIC_SECRET'))) {
            throw new RuntimeException('Development data or absolute development path in archive.');
        }
    }
    $count = $zip->numFiles;
    $source = $base . '/artifact-source'; mkdir($source, 0700); $zip->extractTo($source); $zip->close();
    $consumer = $base . '/consumer'; mkdir($consumer, 0700);
    file_put_contents($consumer . '/composer.json', json_encode([
        'name'=>'print-bridge-check/isolated-consumer', 'license'=>'proprietary',
        'repositories'=>[
            ['type'=>'path','url'=>'../artifact-source','options'=>['symlink'=>false,'versions'=>['lfcheng92/print-bridge'=>'dev-main']]],
            ['packagist.org'=>false],
        ],
        'require'=>['lfcheng92/print-bridge'=>'dev-main'],
        'config'=>['allow-plugins'=>false],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    run([...$composer,'install','--no-dev','--no-interaction','--no-plugins','--no-scripts','--prefer-dist'], $consumer, $environment);
    if (is_link($consumer . '/vendor/lfcheng92/print-bridge')) { throw new RuntimeException('Consumer must copy, not symlink.'); }
    rename($source, $base . '/source-hidden');
    $verify = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use Lfcheng\PrintBridge\Queue;
use Lfcheng\PrintBridge\PrintRequest;
use Lfcheng\PrintBridge\Worker;
use Lfcheng\PrintBridge\Status;
use Lfcheng\PrintBridge\Transport\FakeTransport;
$file = (new ReflectionClass(Queue::class))->getFileName();
if (!str_starts_with($file, __DIR__ . '/vendor/')) { throw new RuntimeException('Source directory dependency.'); }
$q = new Queue(dirname(__DIR__) . '/library-state');
$a = $q->enqueue('consumer-key', new PrintRequest('demo', "Synthetic consumer\n", 'text/plain'));
if ($a->status !== Status::Received || $a->id !== $q->enqueue('consumer-key', new PrintRequest('demo', "Synthetic consumer\n", 'text/plain'))->id) { throw new RuntimeException('Enqueue/dedupe failed.'); }
$worker = new Worker($q, new FakeTransport());
if ($worker->runOnce()?->status !== Status::Submitted || $worker->runOnce() !== null) { throw new RuntimeException('Consumer worker failed.'); }
echo "Copied archive consumer library OK\n";
PHP;
    file_put_contents($consumer . '/verify.php', $verify);
    echo run([PHP_BINARY, $consumer . '/verify.php'], $base, $environment);
    $cli = $consumer . '/vendor/bin/print-bridge';
    run([PHP_BINARY, $cli, 'help'], $base, $environment);
    $out = run([PHP_BINARY,$cli,'submit','--state',$base.'/cli-state','--key','cli-consumer','--printer','demo','--mime','text/plain'], $base, $environment, "Synthetic installed CLI\n");
    if (json_decode($out, true, flags: JSON_THROW_ON_ERROR)['status'] !== 'received') { throw new RuntimeException('Installed CLI submit failed.'); }
    $out = run([PHP_BINARY,$cli,'work','--state',$base.'/cli-state','--once'], $base, $environment);
    if (json_decode($out, true, flags: JSON_THROW_ON_ERROR)['status'] !== 'submitted') { throw new RuntimeException('Installed CLI worker failed.'); }
    echo 'Offline consumer: PASS; archive entries: ' . $count . '; archive SHA256: ' . hash_file('sha256', $archive) . "\n";
} finally {
    removeTree($base);
}
