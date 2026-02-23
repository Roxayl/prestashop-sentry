<?php

declare(strict_types=1);

namespace Extalion\Sentry\Helper;

use Extalion\Sentry\Exception\InstallerException;

class Installer
{
    public static function enableSentry(): void
    {
        $customDefinesFile = self::getCustomDefinesFile();

        if (!\file_exists($customDefinesFile)) {
            self::createFile($customDefinesFile);
        }

        $customDefinesFileContent = self::getFileContent($customDefinesFile);
        $sentryEntry = self::getSentryEntry();

        if (self::hasSentryBlock($customDefinesFileContent)) {
            $customDefinesFileContent = self::replaceSentryBlock(
                $customDefinesFileContent,
                $sentryEntry
            );
        } else {
            $customDefinesFileContent .= "\n" . $sentryEntry;
        }

        self::putFileContent($customDefinesFile, $customDefinesFileContent);
    }

    public static function disableSentry(): void
    {
        $customDefinesFile = self::getCustomDefinesFile();

        if (!\file_exists($customDefinesFile)) {
            return;
        }

        $customDefinesFileContent = self::getFileContent($customDefinesFile);

        if (!self::hasSentryBlock($customDefinesFileContent)) {
            return;
        }

        $customDefinesFileContent = self::replaceSentryBlock(
            $customDefinesFileContent,
            ''
        );

        self::putFileContent($customDefinesFile, $customDefinesFileContent);
    }

    private static function createFile(string $file): void
    {
        $parent = \dirname($file);

        if (!\is_writable($parent)) {
            throw new InstallerException("Parent dir \"{$parent}\" is not writable!");
        }

        if (\file_put_contents($file, "<?php\n") === false) {
            throw new InstallerException("Could not create file \"{$file}\"!");
        }
    }

    private static function getCustomDefinesFile(): string
    {
        $rootDir = self::getRootDir();

        return "{$rootDir}/config/defines_custom.inc.php";
    }

    private static function getFileContent(string $file): string
    {
        if (!\is_readable($file)) {
            throw new InstallerException("File \"{$file}\" is not readable!");
        }

        return \file_get_contents($file);
    }

    private static function getRootDir(): string
    {
        return \realpath(__DIR__ . '/../../../../');
    }

    private static function getSentryEntry(): string
    {
        $rootDir = self::getRootDir();

        return <<<TXT
        ###> extsentry ###
        if (file_exists("{$rootDir}/modules/extsentry/vendor/autoload.php")) {
            require_once "{$rootDir}/config/defines.inc.php";
            require_once _PS_CONFIG_DIR_ . 'autoload.php';
            require_once "{$rootDir}/modules/extsentry/vendor/autoload.php";

            \Extalion\Sentry\Helper\SentryRunner::run();
        }
        ###< extsentry ###
        TXT;
    }

    private static function getSentryBlockPattern(): string
    {
        return '/\s*###> extsentry ###.*?###< extsentry ###/s';
    }

    private static function hasSentryBlock(string $fileContent): bool
    {
        return (bool) \preg_match(self::getSentryBlockPattern(), $fileContent);
    }

    private static function replaceSentryBlock(
        string $fileContent,
        string $replacement
    ): string {
        if ($replacement !== '') {
            $replacement = "\n" . $replacement;
        }

        return \preg_replace(
            self::getSentryBlockPattern(),
            $replacement,
            $fileContent
        );
    }

    private static function putFileContent(string $file, string $content): void
    {
        if (!\is_writable($file)) {
            throw new InstallerException("File \"{$file}\" is not writable!");
        }

        if (\file_put_contents($file, \trim($content)) === false) {
            throw new InstallerException("Could not save content to file \"{$file}\".");
        }
    }
}
