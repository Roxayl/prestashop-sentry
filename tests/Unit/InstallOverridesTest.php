<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Exception\InstallerException;
use PHPUnit\Framework\TestCase;

/**
 * Runs ExtSentry::installOverrides() on a stand-in for the PrestaShop Module class.
 */
final class InstallOverridesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = \dirname(__DIR__, 2);

        if (!\defined('_PS_VERSION_')) {
            \define('_PS_VERSION_', '8.1.7');
        }

        if (!\defined('_PS_ROOT_DIR_')) {
            \define('_PS_ROOT_DIR_', \sys_get_temp_dir() . '/extsentry-tests-' . \getmypid());
        }

        require_once $root . '/tests/Support/PrestaShop/Module.php';
        require_once $root . '/tests/Support/PrestaShop/PrestaShopLogger.php';
        require_once $root . '/extsentry.php';
    }

    protected function setUp(): void
    {
        \Module::$overrideOutcomes = [];
        \PrestaShopLogger::$logs = [];

        foreach (['dev', 'prod'] as $environment) {
            @\mkdir(_PS_ROOT_DIR_ . "/var/cache/{$environment}", 0777, true);
            \file_put_contents(_PS_ROOT_DIR_ . "/var/cache/{$environment}/class_index.php", '<?php return [];');
        }
    }

    protected function tearDown(): void
    {
        foreach (['dev', 'prod'] as $environment) {
            @\unlink(_PS_ROOT_DIR_ . "/var/cache/{$environment}/class_index.php");
            @\rmdir(_PS_ROOT_DIR_ . "/var/cache/{$environment}");
        }

        @\rmdir(_PS_ROOT_DIR_ . '/var/cache');
        @\rmdir(_PS_ROOT_DIR_ . '/var');
        @\rmdir(_PS_ROOT_DIR_);
    }

    public function testTheThreeOverridesAreInstalledPrestaShopExceptionFirst(): void
    {
        $module = new \ExtSentry();

        self::assertTrue($module->installOverrides());
        self::assertSame(['PrestaShopException', 'DbPDO', 'Hook'], $module->attemptedOverrides);
        self::assertSame([], \PrestaShopLogger::$logs);
    }

    public function testATracingOverrideInConflictIsLoggedAndTheModuleStillInstalls(): void
    {
        \Module::$overrideOutcomes = [
            'DbPDO' => new \Exception('The method _query in the class DbPDO is already overridden.'),
            'Hook' => false,
        ];
        $module = new \ExtSentry();

        self::assertTrue($module->installOverrides());
        self::assertCount(2, \PrestaShopLogger::$logs);
        self::assertSame(\PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING, \PrestaShopLogger::$logs[0][0]);
        self::assertStringContainsString('The DbPDO override could not be installed', \PrestaShopLogger::$logs[0][1]);
        self::assertStringContainsString('already overridden', \PrestaShopLogger::$logs[0][1]);
        self::assertStringContainsString('The Hook override could not be installed', \PrestaShopLogger::$logs[1][1]);
    }

    public function testAConflictOnThePrestaShopExceptionOverrideStopsTheActivation(): void
    {
        $conflict = new \Exception('The method displayMessage in the class PrestaShopException is already overridden.');
        \Module::$overrideOutcomes = ['PrestaShopException' => $conflict];
        $module = new \ExtSentry();

        try {
            $module->installOverrides();
            self::fail('The conflict must reach Module::enable().');
        } catch (\Exception $ex) {
            self::assertSame($conflict, $ex);
        }

        self::assertSame(['PrestaShopException'], $module->attemptedOverrides);
    }

    public function testAPrestaShopExceptionOverrideThatIsNotInstalledStopsTheActivation(): void
    {
        \Module::$overrideOutcomes = ['PrestaShopException' => false];
        $module = new \ExtSentry();

        $this->expectException(InstallerException::class);

        $module->installOverrides();
    }

    public function testTheClassIndexOfBothEnvironmentsIsRemoved(): void
    {
        \Module::$overrideOutcomes = ['Hook' => false];

        (new \ExtSentry())->installOverrides();

        self::assertFileDoesNotExist(_PS_ROOT_DIR_ . '/var/cache/dev/class_index.php');
        self::assertFileDoesNotExist(_PS_ROOT_DIR_ . '/var/cache/prod/class_index.php');
    }
}
