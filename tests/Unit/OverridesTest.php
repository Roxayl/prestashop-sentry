<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Tests\Support\FakeModule;
use Extalion\Sentry\Tests\Support\SentryTestCase;
use Extalion\Sentry\Tracing\RequestTransaction;

/**
 * Runs the override methods PrestaShop copies into the shop, on stand-ins for the core classes they extend.
 */
final class OverridesTest extends SentryTestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = \dirname(__DIR__, 2);
        require_once $root . '/tests/Support/PrestaShop/DbPDOCore.php';
        require_once $root . '/tests/Support/PrestaShop/HookCore.php';
        require_once $root . '/override/classes/db/DbPDO.php';
        require_once $root . '/override/classes/Hook.php';
    }

    public function testQueriesAndHooksOfATracedRequestReachItsTransaction(): void
    {
        $db = new \DbPDO();
        $db->result = [['id_product' => 42]];
        $this->startRequestTransaction();

        self::assertSame([['id_product' => 42]], $db->query("SELECT id_product FROM a WHERE email = 'jane@example.com'"));
        self::assertSame('home for 7', \Hook::coreCallHook(new FakeModule(), 'hookDisplayHome', ['customer' => 7]));
        RequestTransaction::close();
        $data = $this->lastEvent()->getContexts()['trace']['data'] ?? [];

        self::assertSame(1, $data['db.query_count'] ?? null);
        self::assertStringEndsWith('SELECT id_product FROM a WHERE email = ?', $data['db.top'][0] ?? '');
        self::assertSame(1, $data['hooks.count'] ?? null);
        self::assertStringEndsWith('fakemodule::hookDisplayHome', $data['hooks.top'][0] ?? '');
    }

    public function testWithoutATracedRequestTheOverridesOnlyCallTheCore(): void
    {
        $db = new \DbPDO();
        $db->result = [['id_product' => 42]];

        self::assertSame([['id_product' => 42]], $db->query('SELECT 1'));
        self::assertSame(['SELECT 1'], $db->executed);
        self::assertSame('home for 7', \Hook::coreCallHook(new FakeModule(), 'hookDisplayHome', ['customer' => 7]));
        self::assertSame([], $this->sentTransactions());
    }

    public function testAFailingQueryKeepsItsExceptionAndRunsOnce(): void
    {
        $db = new \DbPDO();
        $failure = new \RuntimeException('Table does not exist');
        $db->failure = $failure;
        $this->startRequestTransaction();

        try {
            $db->query('SELECT * FROM missing');
            self::fail('The query exception must reach the caller.');
        } catch (\RuntimeException $ex) {
            self::assertSame($failure, $ex);
        }

        RequestTransaction::close();

        self::assertSame(['SELECT * FROM missing'], $db->executed);
        self::assertSame(1, $this->lastEvent()->getContexts()['trace']['data']['db.error_count'] ?? null);
    }
}
