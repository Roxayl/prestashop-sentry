<?php

declare(strict_types=1);

// Stands in for the PrestaShop logger and keeps the messages for the assertions.
class PrestaShopLogger
{
    public const LOG_SEVERITY_LEVEL_WARNING = 2;

    /** @var array<int, array{0: int, 1: string}> */
    public static array $logs = [];

    public static function addLog($message, $severity = 1)
    {
        self::$logs[] = [(int) $severity, (string) $message];

        return true;
    }
}
