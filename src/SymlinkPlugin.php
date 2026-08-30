<?php

namespace Pylesoft\SymlinkPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use RuntimeException;

class SymlinkPlugin implements EventSubscriberInterface, PluginInterface
{
    public function activate(Composer $composer, IOInterface $io) {}

    public function deactivate(Composer $composer, IOInterface $io) {}

    public function uninstall(Composer $composer, IOInterface $io) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'pre-autoload-dump' => 'handle',
        ];
    }

    public static function handle(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectRoot = getcwd();
        $configFile = $projectRoot.'/composer.local.json';

        if (! file_exists($configFile)) {
            $io->write('<info>composer.local.json not found. Skipping local package links.</info>');

            return;
        }

        $map = json_decode((string) file_get_contents($configFile), true);

        if (! is_array($map)) {
            $io->writeError('<error>composer.local.json must be a JSON array of objects.</error>');

            return;
        }

        foreach ($map as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] === '' || ! is_string($entry['path'] ?? null) || $entry['path'] === '' || str_contains($entry['path'], "\0")) {
                $io->writeError('<warning>Skipping local package entry with an invalid name or path.</warning>');

                continue;
            }

            $packageName = $entry['name'];
            $isModule = ($entry['module'] ?? false) === true;

            $targetName = $isModule ? ($entry['module-name'] ?? null) : $packageName;

            if (! self::isSafeRelativePath($targetName)) {
                $io->writeError("<warning>Skipping {$packageName}: destination must be a relative child path.</warning>");

                continue;
            }

            $targetDir = $isModule
                ? $projectRoot.'/app-modules/'.$targetName
                : $vendorDir.'/'.$packageName;
            $resolvedPath = realpath($entry['path']);

            if ($resolvedPath === false || ! is_dir($resolvedPath)) {
                $io->writeError("<warning>Skipping {$packageName}: invalid local path {$entry['path']}.</warning>");

                continue;
            }

            try {
                FilesystemLinker::replace($resolvedPath, $targetDir);
            } catch (RuntimeException $exception) {
                $io->writeError("<error>Unable to link {$packageName}: {$exception->getMessage()}</error>");

                continue;
            }

            $io->write("<info>Linked {$packageName} to {$resolvedPath}.</info>");
        }
    }

    private static function isSafeRelativePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || preg_match('/^[a-z]:/i', $path)) {
            return false;
        }

        $segments = preg_split('#[\\\\/]#', $path);

        return $segments !== false && array_intersect($segments, ['', '.', '..']) === [];
    }
}
