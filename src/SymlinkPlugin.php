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
                    $io->write("<warning>⚠️  Skipping $packageName: 'module-name' is required when 'module' is true</warning>");
                    continue;
                }
                $targetDir = $projectRoot . '/app-modules/' . $entry['module-name'];
            } else {
                $targetDir = $vendorDir . '/' . $packageName;
            }

            $resolvedPath = realpath($localPath);
            if (!$resolvedPath || !is_dir($resolvedPath)) {
                $io->write("<warning>⚠️  Invalid path for $packageName: $localPath</warning>");
                continue;
            }

            // Remove existing target if needed
            if (file_exists($targetDir) || is_link($targetDir)) {
                $io->write("<info>♻️  Removing existing: $targetDir</info>");
                if (!self::removeDirectory($targetDir)) {
                    $io->write("<warning>⚠️  Failed to remove existing directory: $targetDir</warning>");
                }
            }

            // Ensure parent directory exists
            $parentDir = dirname($targetDir);
            if (!is_dir($parentDir)) {
                if (!mkdir($parentDir, 0777, true)) {
                    $io->write("<error>❌ Failed to create parent directory: $parentDir</error>");
                    continue;
                }
                $io->write("<info>📁 Created parent directory: $parentDir</info>");
            }

            // Create the symlink (cross-platform)
            if (self::createSymlink($resolvedPath, $targetDir, $io)) {
                $io->write("<info>✅ Symlinked $packageName → $targetDir</info>");
            } else {
                $io->write("<error>❌ Failed to symlink $packageName</error>");
            }
        }
    }

    private static function removeDirectory(string $path): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // On Windows, handle both junctions and regular directories
                $normalizedPath = str_replace('/', '\\', $path);
                
                // Check if it's a junction point first
                $command = sprintf('dir "%s" | findstr "<JUNCTION>"', dirname($normalizedPath));
                exec($command, $output, $returnCode);
                $isJunction = $returnCode === 0 && !empty($output);
                
                if ($isJunction) {
                    // Remove junction with rmdir (doesn't delete target content)
                    $command = sprintf('rmdir "%s"', $normalizedPath);
                    exec($command, $output, $returnCode);
                    return $returnCode === 0;
                } elseif (is_dir($path)) {
                    // Regular directory - use PowerShell for reliable removal
                    $command = sprintf('powershell -Command "if (Test-Path \'%s\') { Remove-Item -Path \'%s\' -Recurse -Force }"', $path, $path);
                    exec($command, $output, $returnCode);
                    return $returnCode === 0 && !file_exists($path);
                } elseif (is_file($path) || is_link($path)) {
                    return unlink($path);
                }
            } else {
                // Unix/Linux/Mac
                if (is_link($path)) {
                    return unlink($path);
                } elseif (is_file($path)) {
                    return unlink($path);
                } elseif (is_dir($path)) {
                    exec('rm -rf ' . escapeshellarg($path), $output, $returnCode);
                    return $returnCode === 0;
                }
            }
            
            return true; // Path doesn't exist, consider it "removed"
        } catch (Exception $e) {
            return false;
        }
    }

    private static function removeDirectoryRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);
        if ($files === false) {
            return false;
        }

        $files = array_diff($files, ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                if (!self::removeDirectoryRecursive($path)) {
                    return false;
                }
            } else {
                // Handle read-only files on Windows
                if (PHP_OS_FAMILY === 'Windows' && !is_writable($path)) {
                    if (!chmod($path, 0666)) {
                        return false;
                    }
                }
                if (!unlink($path)) {
                    return false;
                }
            }
        }
        
        return rmdir($dir);
    }

    private static function createSymlink(string $target, string $link, IOInterface $io): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows, use NTFS junctions like Composer does (works without admin privileges)
            // Use forward slashes and remove quotes from paths for mklink
            $normalizedTarget = str_replace('/', '\\', $target);
            $normalizedLink = str_replace('/', '\\', $link);
            
            $command = sprintf('mklink /J "%s" "%s"', $normalizedLink, $normalizedTarget);
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return true;
            }
            
            // If junction fails, try directory symlink
            $io->write("<warning>⚠️  Junction failed, trying directory symlink</warning>");
            $command = sprintf('mklink /D "%s" "%s"', $normalizedLink, $normalizedTarget);
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return true;
            }
            
            // Final fallback to copying
            $io->write("<warning>⚠️  Symlink methods failed, falling back to directory copy</warning>");
            return self::copyDirectory($target, $link);
        }
        
        // Unix/Linux/Mac - use native symlink
        return symlink($target, $link);
    }

    private static function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }
            } else {
                copy($item, $targetPath);
            }
        }

        return true;
    }
}
