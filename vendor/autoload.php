<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $filename = sprintf('%s/../src/%s.php', __DIR__, str_replace('\\', '/', $class));

    if (is_readable($filename)) {
        require $filename;
    }
});
