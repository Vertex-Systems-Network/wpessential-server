<?php
/**
 * Builds artifacts/wpessential-server.zip (installable plugin ZIP) from this repository.
 *
 * Only runtime files are packed (see RUNTIME); every entry gets a fixed timestamp, so the same
 * source always gives the same ZIP.
 *
 * Usage: php tools/build-zip.php
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$target  = $root . '/artifacts/wpessential-server.zip';
$runtime = ['wpessential-server.php', 'README.md', 'src', 'assets', 'languages'];

if (!is_file($root . '/wpessential-server.php') || !is_file($root . '/src/HostLock.php')) {
    fwrite(STDERR, "WPE Server source or its host lock is missing.\n");
    exit(1);
}
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
    fwrite(STDERR, "Cannot create artifacts/.\n");
    exit(1);
}

$paths = [];
foreach ($runtime as $entry) {
    $full = $root . '/' . $entry;
    if (is_file($full)) {
        $paths[] = $entry;
        continue;
    }
    if (!is_dir($full)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
sort($paths, SORT_STRING);

foreach ($paths as $relative) {
    if (str_ends_with($relative, '.php') && $relative !== 'wpessential-server.php') {
        $lint = shell_exec('php -l ' . escapeshellarg($root . '/' . $relative) . ' 2>&1');
        if (!is_string($lint) || !str_contains($lint, 'No syntax errors')) {
            fwrite(STDERR, "PHP syntax error in {$relative}:\n{$lint}");
            exit(1);
        }
    }
}

@unlink($target);
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write {$target}.\n");
    exit(1);
}
foreach ($paths as $relative) {
    $name = 'wpessential-server/' . $relative;
    $zip->addFile($root . '/' . $relative, $name);
    $zip->setMtimeName($name, 315532800);
}
$zip->close();

if (!is_file($target)) {
    fwrite(STDERR, "ZIP was not written.\n");
    exit(1);
}
fwrite(STDOUT, sprintf("WPE Server package: %s (%d files, sha256 %s)\n", $target, count($paths), hash_file('sha256', $target)));
