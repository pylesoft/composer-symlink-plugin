<?php

use Pylesoft\SymlinkPlugin\FilesystemLinker;

require_once dirname(__DIR__).'/src/FilesystemLinker.php';

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'composer symlink plugin-'.bin2hex(random_bytes(6));
$source = $root.DIRECTORY_SEPARATOR.'source';
$destination = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'example'.DIRECTORY_SEPARATOR.'package';

$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    mkdir($source, 0777, true);
    file_put_contents($source.DIRECTORY_SEPARATOR.'marker.txt', 'first');

    FilesystemLinker::replace($source, $destination);

    $expect(file_get_contents($destination.DIRECTORY_SEPARATOR.'marker.txt') === 'first', 'The initial local package link is unreadable.');

    file_put_contents($source.DIRECTORY_SEPARATOR.'marker.txt', 'second');

    $expect(file_get_contents($destination.DIRECTORY_SEPARATOR.'marker.txt') === 'second', 'The destination is a stale copy instead of a live link.');

    FilesystemLinker::replace($source, $destination);
    file_put_contents($source.DIRECTORY_SEPARATOR.'after-replace.txt', 'visible');

    $expect(file_get_contents($destination.DIRECTORY_SEPARATOR.'after-replace.txt') === 'visible', 'Replacing an existing local package link failed.');

    echo 'FilesystemLinker test passed on '.PHP_OS_FAMILY.PHP_EOL;
} finally {
    FilesystemLinker::remove($destination);
    FilesystemLinker::remove($root);
}
