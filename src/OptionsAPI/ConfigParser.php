<?php

declare(strict_types=1);

namespace OptionsAPI;

use RuntimeException;

class ConfigParser
{
    private function __construct()
    {
    }

    public static function load(string $filename): void
    {
        if (!is_readable($filename)) {
            throw new RuntimeException("Configuration file {$filename} is missing or unreadable");
        }

        foreach (file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if (strlen($line)) {
                putenv(preg_replace('/\s*=\s*/', '=', $line, 1));
            }
        }
    }
}
