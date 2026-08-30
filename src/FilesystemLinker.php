<?php

namespace Pylesoft\SymlinkPlugin;

use FilesystemIterator;
use RuntimeException;

final class FilesystemLinker
{
    public static function replace(string $source, string $destination): void
    {
        $resolvedSource = realpath($source);

        if ($resolvedSource === false || ! is_dir($resolvedSource)) {
            throw new RuntimeException("Local package source does not exist: {$source}");
        }

        self::remove($destination);

        $parent = dirname($destination);

        if (! is_dir($parent) && ! mkdir($parent, 0777, true) && ! is_dir($parent)) {
            throw new RuntimeException("Unable to create local package parent directory: {$parent}");
        }

        if (PHP_OS_FAMILY === 'Windows') {
            self::createWindowsJunction($resolvedSource, $destination);

            return;
        }

        if (! symlink($resolvedSource, $destination) || ! self::samePath($resolvedSource, $destination)) {
            throw new RuntimeException("Unable to create local package symlink: {$destination}");
        }
    }

    public static function remove(string $path): void
    {
        if (! self::exists($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException("Unable to remove local package link: {$path}");
            }

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $metadata = @lstat($path);

            if ($metadata === false) {
                throw new RuntimeException("Unable to inspect local package destination: {$path}");
            }

            if (($metadata['mode'] ?? 0) === 0) {
                if (! @rmdir($path)) {
                    throw new RuntimeException("Unable to remove local package junction: {$path}");
                }

                return;
            }

            if (@rmdir($path)) {
                return;
            }
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $itemPath = $item->getPathname();

            if ($item->isLink() || $item->isFile()) {
                if (! $item->isWritable()) {
                    @chmod($itemPath, 0666);
                }

                if (! unlink($itemPath)) {
                    throw new RuntimeException("Unable to remove local package file: {$itemPath}");
                }

                continue;
            }

            self::remove($itemPath);
        }

        if (! rmdir($path)) {
            throw new RuntimeException("Unable to remove local package directory: {$path}");
        }
    }

    private static function createWindowsJunction(string $source, string $destination): void
    {
        $process = proc_open(
            'cmd.exe /D /V:ON /C mklink /J "!PYLE_DESTINATION!" "!PYLE_SOURCE!"',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: array_merge(getenv(), [
                'PYLE_DESTINATION' => $destination,
                'PYLE_SOURCE' => $source,
            ]),
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Unable to start local package junction creation: {$destination}");
        }

        $output = trim(stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! self::samePath($source, $destination)) {
            throw new RuntimeException(sprintf(
                'Unable to create local package junction: %s%s',
                $destination,
                $output === '' ? '' : PHP_EOL.$output,
            ));
        }
    }

    private static function samePath(string $expected, string $actual): bool
    {
        clearstatcache(true, $expected);
        clearstatcache(true, $actual);

        $resolvedExpected = realpath($expected);
        $resolvedActual = realpath($actual);

        if ($resolvedExpected === false || $resolvedActual === false) {
            return false;
        }

        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));

        return $normalize($resolvedExpected) === $normalize($resolvedActual);
    }

    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path) || @lstat($path) !== false;
    }
}
