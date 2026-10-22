<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

use Lfcheng\PrintBridge\Exception\BridgeException;

/** Local, same-user storage only. Parent directories must be trusted. */
final class PrivateFiles
{
    public static function directory(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1 || $path === '/') {
            throw new \InvalidArgumentException('State path must be an absolute local directory without dot segments.');
        }
        $path = rtrim($path, '/');
        if (is_link($path)) {
            throw new BridgeException('Symlink state directories are not permitted.');
        }
        if (!file_exists($path)) {
            $parent = realpath(dirname($path));
            if ($parent === false || !is_dir($parent)) {
                throw new BridgeException('State directory parent must already exist.');
            }
            if (!@mkdir($path, 0700) && !is_dir($path)) {
                throw new BridgeException('Cannot create private state directory.');
            }
        }
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        $real = realpath($path);
        if (!is_dir($path) || $mode === false || ($mode & 0077) !== 0 || $real === false || is_link($path)) {
            throw new BridgeException('State directory must be private (0700) and not a symlink.');
        }
        return $real;
    }

    /** @return resource */
    public static function create(string $path)
    {
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new BridgeException('Cannot create private file.');
        }
        return $handle;
    }

    public static function assertFile(string $path): void
    {
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (is_link($path) || !is_file($path) || $mode === false || ($mode & 0077) !== 0) {
            throw new BridgeException('Expected a private regular file.');
        }
    }

    /** @param resource $handle */
    public static function write($handle, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new BridgeException('Cannot write private file.');
            }
            $offset += $written;
        }
        if (!@fflush($handle) || !@fsync($handle)) {
            throw new BridgeException('Cannot synchronize private file.');
        }
    }
}
