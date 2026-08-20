<?php

declare(strict_types=1);

namespace App\Core;

final class Version
{
    public const VERSION = '1.0.0';

    public static function get(): string
    {
        $versionFile = dirname(__DIR__, 2) . '/VERSION';
        if (is_file($versionFile) && is_readable($versionFile)) {
            $v = trim((string) file_get_contents($versionFile));
            if ($v !== '') {
                return $v;
            }
        }
        return self::VERSION;
    }
}
