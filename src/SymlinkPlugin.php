<?php

namespace Pylesoft\SymlinkPlugin;

use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\Event;
use Composer\Composer;
use Composer\IO\IOInterface;

class SymlinkPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io) {}
    public function deactivate(Composer $composer, IOInterface $io) {}
    public function uninstall(Composer $composer, IOInterface $io) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'pre-autoload-dump' => 'handle'
        ];
    }

    public static function handle(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectRoot = getcwd();
        $configFile = $projectRoot . '/composer.local.json';

        if (!file_exists($configFile)) {
            $io->write("<info>🔗 composer.local.json not found. Skipping symlink operations.</info>");
            return;
        }

        $json = file_get_contents($configFile);
        $map = json_decode($json, true);

        if (!is_array($map)) {
            $io->write("<error>❌ composer.local.json must be a JSON array of objects.</error>");
            return;
        }

        foreach ($map as $entry) {
            if (!isset($entry['name'], $entry['path'])) {
                $io->write("<warning>⚠️  Skipping entry: missing 'name' or 'path'</warning>");
                continue;
            }

            $packageName = $entry['name'];
            $localPath = $entry['path'];
            $isModule = isset($entry['module']) && $entry['module'] === true;

            if ($isModule) {
                if (!isset($entry['module-name'])) {
                    $io->write("<warning>⚠️  Skipping $packageName: 'module-name' required when 'module' is true</warning>");
                    continue;
                }
                $targetDir = $projectRoot . '/app-modules/' . $entry['module-name'];
            } else {
                $targetDir = $vendorDir . '/' . $packageName;
            }

            $realPath = realpath($localPath);
            if (!$realPath || !is_dir($realPath)) {
                $io->write("<warning>⚠️  Invalid path for $packageName: $localPath</warning>");
                continue;
            }

            if (file_exists($targetDir) || is_link($targetDir)) {
                $io->write("♻️  Removing existing $targetDir");
                exec('rm -rf ' . escapeshellarg($targetDir));
            }

            if (!is_dir(dirname($targetDir))) {
                mkdir(dirname($targetDir), 0777, true);
            }

            if (symlink($realPath, $targetDir)) {
                $io->write("<info>✅ Symlinked $packageName → $targetDir</info>");
            } else {
                $io->write("<error>❌ Failed to symlink $packageName</error>");
            }
        }
    }
}
