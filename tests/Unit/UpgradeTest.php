<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Helper\SettingsFile;
use PHPUnit\Framework\TestCase;

/**
 * Runs the 0.3.0 upgrade script on stand-ins for the PrestaShop Module, Db and logger classes.
 */
final class UpgradeTest extends TestCase
{
    private string $moduleDirectory;

    public static function setUpBeforeClass(): void
    {
        $root = \dirname(__DIR__, 2);

        foreach (['_PS_VERSION_' => '8.1.7', '_DB_PREFIX_' => 'ps_', '_PS_ROOT_DIR_' => \sys_get_temp_dir() . '/extsentry-tests-' . \getmypid()] as $name => $value) {
            if (!\defined($name)) {
                \define($name, $value);
            }
        }

        if (!\defined('_PS_CONFIG_DIR_')) {
            \define('_PS_CONFIG_DIR_', _PS_ROOT_DIR_ . '/config/');
        }

        require_once $root . '/tests/Support/PrestaShop/Db.php';
        require_once $root . '/tests/Support/PrestaShop/Module.php';
        require_once $root . '/tests/Support/PrestaShop/PrestaShopLogger.php';
        require_once $root . '/extsentry.php';
        require_once $root . '/upgrade/upgrade-0.3.0.php';
    }

    protected function setUp(): void
    {
        $this->moduleDirectory = \sys_get_temp_dir() . '/extsentry-module-' . \uniqid() . '/';
        @\mkdir($this->moduleDirectory, 0777, true);
        @\mkdir(_PS_CONFIG_DIR_, 0777, true);
        \Module::$localPath = $this->moduleDirectory;
        \Db::$value = false;
        \PrestaShopLogger::$logs = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->moduleDirectory . 'sentry.json');
        @\rmdir($this->moduleDirectory);
        @\unlink(SettingsFile::getPath());
        @\rmdir(_PS_CONFIG_DIR_);
    }

    public function testTheSettingsOfSentryJsonMoveToTheSettingsFile(): void
    {
        \file_put_contents($this->moduleDirectory . 'sentry.json', \json_encode(['dsn' => 'https://public@example.ingest.sentry.io/1', 'sample_rate' => '1']));

        self::assertTrue(\upgrade_module_0_3_0(new \ExtSentry()));
        self::assertSame(['dsn' => 'https://public@example.ingest.sentry.io/1', 'sample_rate' => '1'], SettingsFile::read());
        self::assertFileDoesNotExist($this->moduleDirectory . 'sentry.json');
        self::assertSame([], \PrestaShopLogger::$logs);
    }

    public function testWithoutSentryJsonTheUpgradeWritesNoSettings(): void
    {
        self::assertTrue(\upgrade_module_0_3_0(new \ExtSentry()));
        self::assertFileDoesNotExist(SettingsFile::getPath());
    }
}
