<?php

spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    $file = $baseDir . $class . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
