<?php

// Run in a disposable, migrated Laravel host fixture; never against production.
$host = getenv('WA_OTP_TEST_HOST');
if (! $host || ! is_file($host.'/vendor/autoload.php')) {
    throw new RuntimeException('Set WA_OTP_TEST_HOST to the absolute Laravel fixture path.');
}
$loader = require $host.'/vendor/autoload.php';
$root = dirname(__DIR__).'/app';
$map = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $map['App\\'.str_replace(['/', '\\'], '\\', $relative)] = $file->getPathname();
    }
}
$loader->addClassMap($map);
chdir($host);
