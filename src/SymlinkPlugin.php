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
            if (! isset($entry['name'], $entry['path'])) {
                $io->writeError('<warning>Skipping local package entry without a name or path.</warning>');

                continue;
            }

            $packageName = $entry['name'];
            $isModule = ($entry['module'] ?? false) === true;

            if ($isModule && ! isset($entry['module-name'])) {
                $io->writeError("<warning>Skipping {$packageName}: module-name is required for modules.</warning>");

                continue;
            }

            $targetDir = $isModule
                ? $projectRoot.'/app-modules/'.$entry['module-name']
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
}
