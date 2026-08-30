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

        if (! symlink($resolvedSource, $destination)) {
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

        if (PHP_OS_FAMILY === 'Windows' && self::isWindowsReparsePoint($path)) {
            self::removeWindowsJunction($path);

            return;
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
        exec(sprintf(
            'cmd.exe /D /C mklink /J %s %s',
            self::windowsArgument($destination),
            self::windowsArgument($source),
        ), $output, $exitCode);

        if ($exitCode !== 0 || ! self::samePath($source, $destination)) {
            throw new RuntimeException(sprintf(
                'Unable to create local package junction: %s%s',
                $destination,
                $output === [] ? '' : PHP_EOL.implode(PHP_EOL, $output),
            ));
        }
    }

    private static function removeWindowsJunction(string $path): void
    {
        exec(
            'cmd.exe /D /C rmdir '.self::windowsArgument($path),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0 || self::exists($path)) {
            throw new RuntimeException("Unable to remove local package junction: {$path}");
        }
    }

    private static function isWindowsReparsePoint(string $path): bool
    {
        $parent = dirname($path);
        $name = basename($path);

        exec(
            'cmd.exe /D /C dir /A /B '.self::windowsArgument($parent).' 2>NUL',
            $entries,
            $entriesExitCode,
        );

        if ($entriesExitCode !== 0 || ! self::containsWindowsEntry($entries, $name)) {
            throw new RuntimeException("Unable to inspect local package destination: {$path}");
        }

        exec(
            'cmd.exe /D /C dir /A:L /B '.self::windowsArgument($parent).' 2>NUL',
            $links,
        );

        return self::containsWindowsEntry($links, $name);
    }

    /** @param list<string> $entries */
    private static function containsWindowsEntry(array $entries, string $name): bool
    {
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function windowsArgument(string $path): string
    {
        if (str_contains($path, '"') || str_contains($path, "\0")) {
            throw new RuntimeException('Windows local package paths cannot contain quotes or null bytes.');
        }

        return '"'.$path.'"';
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
