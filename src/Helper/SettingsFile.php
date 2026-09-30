<?php

declare(strict_types=1);

namespace Extalion\Sentry\Helper;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class SettingsFile
{
    private const FILE_NAME = 'extsentry.yml';

    public static function getPath(): string
    {
        return \rtrim(_PS_CONFIG_DIR_, '/') . '/' . self::FILE_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(?string $path = null): array
    {
        $path = $path ?? self::getPath();

        if (!\is_file($path)) {
            return [];
        }

        try {
            $settings = Yaml::parseFile($path);
        } catch (ParseException $ex) {
            \error_log("[extsentry] Unable to read {$path}: " . $ex->getMessage());

            return [];
        }

        return \is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function write(array $settings, ?string $path = null): bool
    {
        $path = $path ?? self::getPath();

        if (\file_put_contents($path, Yaml::dump($settings), \LOCK_EX) === false) {
            return false;
        }

        return \chmod($path, 0600);
    }

    /**
     * Moves the settings of the sentry.json file used before 0.3.0; settings already in the YAML file win.
     */
    public static function importLegacyJson(string $jsonPath, ?string $path = null): bool
    {
        if (!\is_file($jsonPath)) {
            return false;
        }

        $legacy = (array) \json_decode((string) \file_get_contents($jsonPath), true);

        if (!self::write(\array_merge($legacy, self::read($path)), $path)) {
            return false;
        }

        return \unlink($jsonPath);
    }
}
