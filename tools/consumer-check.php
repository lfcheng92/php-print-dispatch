<?php
declare(strict_types=1);

// Offline consumer test: archive, inspect, extract, copy-install, hide source, execute library + bin.
$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/print-dispatch-consumer-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$composerPath = getenv('PRINT_DISPATCH_COMPOSER') ?: 'composer';
$composer = is_file($composerPath) ? [PHP_BINARY, $composerPath] : [$composerPath];
$environment = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LC_ALL' => 'C',
    'COMPOSER_HOME' => $base . '/home', 'COMPOSER_CACHE_DIR' => $base . '/cache',
    'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ROOT_VERSION' => 'dev-main',
    'TMPDIR' => $base, 'TMP' => $base, 'TEMP' => $base,
];

function run(array $command, string $directory, array $environment, string $stdin = '', int $expectedExit = 0): string
{
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $directory, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run consumer check command.'); }
    if ($stdin !== '') { fwrite($pipes[0], $stdin); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== $expectedExit) { throw new RuntimeException('Consumer command failed: ' . $out . $err); }
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
    run([...$composer, 'archive', '--format=zip', '--dir=' . $base . '/archives', '--file=print-dispatch', '--no-interaction', '--no-plugins', '--no-scripts'], $root, $environment);
    $archive = $base . '/archives/print-dispatch.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot inspect source archive.'); }
    $required = ['composer.json', 'src/Queue.php', 'bin/print-dispatch', 'README.md', 'README.en.md', 'SECURITY.md', 'LICENSE', 'PROVENANCE.md', 'TESTING.md'];
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
        'name'=>'print-dispatch-check/isolated-consumer', 'description'=>'Isolated copied-archive verification fixture.', 'license'=>'proprietary',
        'repositories'=>[
            ['type'=>'path','url'=>'../artifact-source','options'=>['symlink'=>false,'versions'=>['lfcheng92/print-dispatch'=>'dev-main']]],
            ['packagist.org'=>false],
        ],
        'require'=>['lfcheng92/print-dispatch'=>'dev-main'],
        'config'=>['allow-plugins'=>false],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    run([...$composer,'install','--no-dev','--no-interaction','--no-plugins','--no-scripts','--prefer-dist'], $consumer, $environment);
    if (is_link($consumer . '/vendor/lfcheng92/print-dispatch')) { throw new RuntimeException('Consumer must copy, not symlink.'); }
    run([...$composer,'validate','--strict','--no-plugins','--no-scripts'], $consumer, $environment);
    run([...$composer,'dump-autoload','--optimize','--strict-psr','--strict-ambiguous','--no-dev','--no-plugins','--no-scripts'], $consumer, $environment);
    run([...$composer,'check-platform-reqs','--no-dev'], $consumer, $environment);
    rename($source, $base . '/source-hidden');
    $verify = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use Lfcheng\PrintDispatch\Queue;
use Lfcheng\PrintDispatch\PrintRequest;
use Lfcheng\PrintDispatch\Worker;
use Lfcheng\PrintDispatch\Status;
use Lfcheng\PrintDispatch\Transport\FakeTransport;
$file = (new ReflectionClass(Queue::class))->getFileName();
if (!str_starts_with($file, __DIR__ . '/vendor/')) { throw new RuntimeException('Source directory dependency.'); }
$classMap = require __DIR__ . '/vendor/composer/autoload_classmap.php';
$types = 0;
foreach ($classMap as $class => $path) {
    if (!str_starts_with($class, 'Lfcheng\\PrintDispatch\\')) { continue; }
    if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
        throw new RuntimeException('Installed type cannot load: ' . $class);
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->getName() !== $class || !str_starts_with($reflection->getFileName(), __DIR__ . '/vendor/lfcheng92/print-dispatch/src/')) {
        throw new RuntimeException('Installed type name or source mismatch.');
    }
    ++$types;
}
$sourceFiles = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/vendor/lfcheng92/print-dispatch/src', FilesystemIterator::SKIP_DOTS)));
if ($types !== $sourceFiles || $types !== 22) { throw new RuntimeException('Runtime type inventory mismatch.'); }
$q = new Queue(dirname(__DIR__) . '/library-state');
$a = $q->enqueue('consumer-key', new PrintRequest('demo', "Synthetic consumer\n", 'text/plain'));
if ($a->status !== Status::Received || $a->id !== $q->enqueue('consumer-key', new PrintRequest('demo', "Synthetic consumer\n", 'text/plain'))->id) { throw new RuntimeException('Enqueue/dedupe failed.'); }
$worker = new Worker($q, new FakeTransport());
$simulated = $worker->runOnce();
if ($simulated?->status !== Status::Simulated || $simulated->systemJobId !== null || !$simulated->jsonSerialize()['simulated'] || $worker->runOnce() !== null) { throw new RuntimeException('Consumer simulation boundary failed.'); }
$purgeBlocked = false;
try { $q->purgeDocument($a->id); } catch (\Lfcheng\PrintDispatch\Exception\BridgeException) { $purgeBlocked = true; }
if (!$purgeBlocked || !is_file($q->documentPath($a))) { throw new RuntimeException('Simulated payload was not retained.'); }
$bad = $q->enqueue('corrupt-consumer-key', new PrintRequest('demo', "Synthetic metadata test\n", 'text/plain'));
$db = new PDO('sqlite:' . dirname(__DIR__) . '/library-state/queue.sqlite');
$statement = $db->prepare('UPDATE jobs SET copies=1000 WHERE id=?'); $statement->execute([$bad->id]);
$fake = new FakeTransport(); $failed = (new Worker($q, $fake))->runOnce();
if ($failed?->status !== Status::Failed || $failed->attempts !== 0 || $fake->submissions !== []) {
    throw new RuntimeException('Installed metadata validation boundary failed.');
}
echo "Copied archive consumer library OK; runtime types: " . $types . "\n";
PHP;
    file_put_contents($consumer . '/verify.php', $verify);
    echo run([PHP_BINARY, $consumer . '/verify.php'], $base, $environment);
    $cli = $consumer . '/vendor/bin/print-dispatch';
    $help = run([PHP_BINARY, $cli, 'help'], $base, $environment);
    $withoutSetting = run([PHP_BINARY, '-d', 'register_argc_argv=0', $cli, 'help'], $base, $environment,
        '', PHP_VERSION_ID >= 80500 ? 0 : 1);
    if ($withoutSetting !== (PHP_VERSION_ID >= 80500 ? $help : '')) {
        throw new RuntimeException('Installed CLI argument configuration mismatch.');
    }
    $missingArguments = 'unset($argv); require ' . var_export($cli, true) . ';';
    if (run([PHP_BINARY, '-r', $missingArguments], $base, $environment, '', 1) !== '') {
        throw new RuntimeException('Installed CLI did not reject missing arguments cleanly.');
    }
    $out = run([PHP_BINARY,$cli,'submit','--state',$base.'/cli-state','--key','cli-consumer','--printer','demo','--mime','text/plain'], $base, $environment, "Synthetic installed CLI\n");
    $submitted = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    if ($submitted['status'] !== 'received') { throw new RuntimeException('Installed CLI submit failed.'); }
    run([PHP_BINARY,$cli,'work','--state',$base.'/cli-state','--once'], $base, $environment, '', 2);
    $out = run([PHP_BINARY,$cli,'status','--state',$base.'/cli-state','--id',$submitted['id']], $base, $environment);
    if (json_decode($out,true,flags:JSON_THROW_ON_ERROR)['status'] !== 'received') { throw new RuntimeException('Omitted transport consumed a real queued job.'); }
    $out = run([PHP_BINARY,$cli,'work','--state',$base.'/cli-state','--once','--transport','fake'], $base, $environment);
    $simulation = json_decode($out,true,flags:JSON_THROW_ON_ERROR);
    if ($simulation['status'] !== 'simulated' || !$simulation['simulated'] || $simulation['system_job_id'] !== null) { throw new RuntimeException('Installed CLI simulation boundary failed.'); }
    run([PHP_BINARY,$cli,'purge','--state',$base.'/cli-state','--id',$submitted['id']], $base, $environment, '', 1);
    echo 'Offline consumer: PASS; archive entries: ' . $count . '; archive SHA256: ' . hash_file('sha256', $archive) . "\n";
} finally {
    removeTree($base);
}
