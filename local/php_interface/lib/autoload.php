<?php
spl_autoload_register(function (string $class): void {
    $prefix = 'Ithive\\Goalsbazhen\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});