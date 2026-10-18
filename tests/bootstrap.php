<?php

$packageRoot = realpath(__DIR__.'/..');

$storageDir = $packageRoot.'/storage/framework/views';
$cacheDir = $packageRoot.'/bootstrap/cache';
$tempDir = $packageRoot.'/storage/.tmp';

foreach ([$storageDir, $cacheDir] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
        throw new RuntimeException(sprintf('Unable to create directory: %s', $dir));
    }
}

foreach (glob($storageDir.'/*.php') ?: [] as $compiledView) {
    // A parallel worker may remove the view between the glob and the read.
    $compiledViewContents = @file_get_contents($compiledView);

    if ($compiledViewContents === false || ! str_contains($compiledViewContents, 'PATH /packages/')) {
        continue;
    }

    if (! @unlink($compiledView) && is_file($compiledView)) {
        throw new RuntimeException(sprintf('Unable to clear compiled view: %s', $compiledView));
    }
}

foreach (['TMPDIR', 'TEMP', 'TMP'] as $key) {
    putenv(sprintf('%s=%s', $key, $tempDir));
}

if (! is_dir($tempDir) && ! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
    throw new RuntimeException(sprintf('Unable to create directory: %s', $tempDir));
}

putenv(sprintf('VIEW_COMPILED_PATH=%s', $storageDir));

$loader = require __DIR__.'/../vendor/autoload.php';
$loader->addPsr4('Basics13\\Tests\\', __DIR__.'/../vendor/amaia/basics13/tests/');
