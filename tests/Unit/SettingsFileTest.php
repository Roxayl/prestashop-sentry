<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Helper\SettingsFile;
use PHPUnit\Framework\TestCase;

final class SettingsFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/extsentry-settings-' . \uniqid();
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    public function testAMissingFileGivesNoSettings(): void
    {
        self::assertSame([], SettingsFile::read($this->path()));
    }

    public function testWrittenSettingsAreReadBack(): void
    {
        $settings = ['dsn' => 'https://public@example.ingest.sentry.io/1', 'sample_rate' => '0.5', 'traces_sample_rate' => ''];

        self::assertTrue(SettingsFile::write($settings, $this->path()));
        self::assertSame($settings, SettingsFile::read($this->path()));
    }

    public function testTheFileIsReadableByItsOwnerOnly(): void
    {
        SettingsFile::write(['dsn' => 'https://public@example.ingest.sentry.io/1'], $this->path());

        self::assertSame('600', \substr(\sprintf('%o', \fileperms($this->path())), -3));
    }

    public function testAnInvalidFileGivesNoSettingsInsteadOfFailing(): void
    {
        \file_put_contents($this->path(), "dsn: [unclosed\n");
        $errorLog = \ini_set('error_log', '/dev/null');

        try {
            self::assertSame([], SettingsFile::read($this->path()));
        } finally {
            \ini_set('error_log', (string) $errorLog);
        }
    }

    public function testLegacyJsonSettingsAreImportedThenRemoved(): void
    {
        $json = $this->directory . '/sentry.json';
        \file_put_contents($json, \json_encode(['dsn' => 'https://public@example.ingest.sentry.io/1', 'sample_rate' => '0.5']));

        self::assertTrue(SettingsFile::importLegacyJson($json, $this->path()));
        self::assertSame(['dsn' => 'https://public@example.ingest.sentry.io/1', 'sample_rate' => '0.5'], SettingsFile::read($this->path()));
        self::assertFileDoesNotExist($json);
    }

    public function testSettingsAlreadyInTheFileWinOverLegacyJson(): void
    {
        $json = $this->directory . '/sentry.json';
        \file_put_contents($json, \json_encode(['dsn' => 'https://old@example.ingest.sentry.io/1', 'server_name' => 'shop']));
        SettingsFile::write(['dsn' => 'https://new@example.ingest.sentry.io/1'], $this->path());

        SettingsFile::importLegacyJson($json, $this->path());

        self::assertSame(['dsn' => 'https://new@example.ingest.sentry.io/1', 'server_name' => 'shop'], SettingsFile::read($this->path()));
    }

    public function testWithoutLegacyJsonNothingIsImported(): void
    {
        self::assertFalse(SettingsFile::importLegacyJson($this->directory . '/sentry.json', $this->path()));
        self::assertFileDoesNotExist($this->path());
    }

    private function path(): string
    {
        return $this->directory . '/extsentry.yml';
    }
}
