<?php

declare(strict_types=1);

namespace Extalion\Sentry\Helper;

use Extalion\Sentry\Consts\SentryConfigFile;

class SentryRunner
{
    public static function run(): void
    {
        $config = self::getConfigFromFile();

        \Sentry\init($config);
    }

    private static function getConfigFromFile(): array
    {
        $configContent = '';
        $configFile = SentryConfigFile::getPath();

        if (\file_exists($configFile)) {
            $configContent = \file_get_contents($configFile);
        }

        $config = (array) \json_decode($configContent, true);
        $errorTypes = (string) ($config['error_types'] ?? '');
        $errorTypes = ErrorTypes::isValid($errorTypes) ? $errorTypes : '';
        $config['error_types'] = eval("return {$errorTypes};");
        $config['sample_rate'] = (float) ($config['sample_rate'] ?? 1);
        $config['environment'] = $config['environment'] ?? null;

        // Disable HTTP compression: it loads php-http/message Encoding streams
        // whose typed signatures are incompatible with the psr/http-message
        // 1.0.1 bundled by PrestaShop 8.x, causing a fatal error on send.
        $config['enable_compression'] = false;

        return $config;
    }
}
