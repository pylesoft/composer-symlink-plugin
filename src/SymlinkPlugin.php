<?php

namespace Pylesoft\SymlinkPlugin;

use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\Event;
use Composer\IO\IOInterface;

class SymlinkPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(\Composer\Composer $composer, IOInterface $io)
    {
        // Nothing needed on activate
    }

    public static function getSubscribedEvents()
    {
        return [
            'pre-autoload-dump' => 'handle',
        ];
    }

    public static function handle(Event $event)
    {
        $projectRoot = getcwd();
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $configPath = $projectRoot . '/composer.local.json';

        if (!file_exists($configPath)) {
            $event->getIO()->write("<info>🔗 composer.local.json not found. Skipping symlinks.</info>");
            return;
        }

        $json = file_get_contents($configPath);
        $map = json_decode($json, true);

        if (!is_array($map)) {
            $event->getIO()->write("<error>❌ composer.local.json is not a valid JSON array.</error>");
            return;
        }

        foreach ($map as $entry) {
            if (!isset($entry['name']) || !isset($entry['path'])) {
                $event->getIO()->write("<warning>⚠️  Invalid entry in composer.local.json: must have 'name' and 'path'.</warning>");
                continue;
            }

            $packageName = $entry['name'];
            $localPath = $entry['path'];

            $targetDir = $vendorDir . '/' . $packageName;
            $realPath = realpath($localPath);

            if (!$realPath || !is_dir($realPath)) {
                $event->getIO()->write("<warning>⚠️  Path for $packageName does not exist: $localPath</warning>");
                continue;
            }

            if (file_exists($targetDir) || is_link($targetDir)) {
                exec('rm -rf ' . escapeshellarg($targetDir));
            }

            if (!is_dir(dirname($targetDir))) {
                mkdir(dirname($targetDir), 0777, true);
            }

            if (symlink($realPath, $targetDir)) {
                $event->getIO()->write("<info>✅ Symlinked $packageName → $realPath</info>");
            } else {
                $event->getIO()->write("<error>❌ Failed to symlink $packageName</error>");
            }
        }
    }
}
