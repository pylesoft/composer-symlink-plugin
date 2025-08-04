<?php

namespace Pylesoft\SymlinkPlugin;

use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\Event;
use Composer\Composer;
use Composer\IO\IOInterface;

class SymlinkPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        // No setup needed at activation
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // No teardown needed at deactivation
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // No cleanup needed at uninstall
    }

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
        $configFile = $projectRoot . '/composer.local.json';

        if (!file_exists($configFile)) {
            $io->write("<info>🔗 composer.local.json not found. Skipping symlink operations.</info>");
            return;
        }

        $json = file_get_contents($configFile);
        $map = json_decode($json, true);

        if (!is_array($map)) {
            $io->write("<error>❌ composer.local.json must contain an array of {\"name\":..., \"path\":...} entries.</error>");
            return;
        }

        foreach ($map as $entry) {
            if (!isset($entry['name']) || !isset($entry['path'])) {
                $io->write("<warning>⚠️  Skipping invalid entry. Must include 'name' and 'path'.</warning>");
                continue;
            }

            $packageName = $entry['name'];
            $localPath = $entry['path'];
            $resolvedPath = realpath($localPath);

            if (!$resolvedPath || !is_dir($resolvedPath)) {
                $io->write("<warning>⚠️  Path for <comment>$packageName</comment> does not exist or is invalid: $localPath</warning>");
                continue;
            }

            $targetDir = $vendorDir . '/' . $packageName;

            if (file_exists($targetDir) || is_link($targetDir)) {
                $io->write("♻️  Removing existing directory/link for <comment>$packageName</comment>");
                exec('rm -rf ' . escapeshellarg($targetDir));
            }

            if (!is_dir(dirname($targetDir))) {
                mkdir(dirname($targetDir), 0777, true);
            }

            if (symlink($resolvedPath, $targetDir)) {
                $io->write("<info>✅ Symlinked <comment>$packageName</comment> → $resolvedPath</info>");
            } else {
                $io->write("<error>❌ Failed to symlink <comment>$packageName</comment></error>");
            }
        }
    }
}
