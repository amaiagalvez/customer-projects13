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
    $compiledViewContents = file_get_contents($compiledView);

    if ($compiledViewContents === false) {
        throw new RuntimeException(sprintf('Unable to read compiled view: %s', $compiledView));
    }

    if (! str_contains($compiledViewContents, 'PATH /packages/')) {
        continue;
    }

    if (! unlink($compiledView)) {
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

require __DIR__.'/../vendor/autoload.php';
