<?php

$autoload = getcwd().'/vendor/autoload.php';

if (! is_file($autoload)) {
    throw new RuntimeException('Run the browser suite from the Laravel application root.');
}

$loader = require $autoload;
$loader->addPsr4('CustomerProjects13\\Tests\\', __DIR__.'/');
