<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Tests\Support\CapturingTransport;
use Extalion\Sentry\Tests\Support\FakeProductController;
use Extalion\Sentry\Tests\Support\SentryTestCase;
use Extalion\Sentry\Tracing\RequestTransaction;
use Extalion\Sentry\Tracing\Tracer;
use Sentry\Event;

final class RequestTransactionTest extends SentryTestCase
{
    public function testNothingIsSentOnTheCommandLineOrWithoutARate(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        foreach ([[1.0, 'cli'], [null, 'apache2handler'], [0.0, 'apache2handler']] as [$rate, $sapi]) {
            RequestTransaction::start($rate, $sapi);
            RequestTransaction::close();
        }

        self::assertSame([], $this->sentTransactions());
    }

    public function testNothingIsTracedWithoutADsn(): void
    {
        $this->bindCapturingClient(['traces_sample_rate' => 1.0, 'default_integrations' => false]);
        $this->startRequestTransaction();

        self::assertFalse(Tracer::isActive());

        RequestTransaction::close();

        self::assertSame([], $this->sentTransactions());
    }

    public function testWithoutAControllerTheTransactionIsNamedAfterTheScript(): void
    {
        $this->startRequestTransaction();
        RequestTransaction::close();

        self::assertSame('POST /index.php', $this->lastEvent()->getTransaction());
    }

    public function testTheControllerNamesTheTransactionAndAjaxIsSuffixed(): void
    {
        $this->startRequestTransaction();
        $controller = new FakeProductController();
        $controller->ajax = true;
        \Context::getContext()->controller = $controller;
        RequestTransaction::close();

        self::assertSame('POST ' . FakeProductController::class . ' (ajax)', $this->lastEvent()->getTransaction());
    }

    public function testASymfonyRouteWinsOverTheController(): void
    {
        $this->startRequestTransaction();
        \Context::getContext()->controller = new FakeProductController();
        RequestTransaction::nameFromRoute('admin_orders_index');
        RequestTransaction::close();

        self::assertSame('POST admin_orders_index', $this->lastEvent()->getTransaction());
    }

    public function testQueriesOfTheRequestAreCountedInItsTransaction(): void
    {
        $this->startRequestTransaction();
        Tracer::traceQuery('SELECT 1', static function () {
            return [];
        });
        RequestTransaction::close();

        self::assertSame(1, $this->lastEvent()->getContexts()['trace']['data']['db.query_count'] ?? null);
    }

    public function testAnErrorCapturedDuringTheRequestIsAttachedToItsTransaction(): void
    {
        $this->startRequestTransaction();
        \Sentry\captureException(new \RuntimeException('during the request'));
        RequestTransaction::close();
        \Sentry\captureException(new \RuntimeException('after the request'));
        [$during, $transaction, $after] = $this->traceIdsInSendingOrder();

        self::assertSame($transaction, $during);
        self::assertNotSame($transaction, $after);
    }

    public function testClosingShutsTheGateTheOverridesRead(): void
    {
        $this->startRequestTransaction();
        RequestTransaction::close();

        self::assertFalse(Tracer::isActive());
    }

    public function testClosingTwiceSendsOneTransaction(): void
    {
        $this->startRequestTransaction();
        RequestTransaction::close();
        RequestTransaction::close();

        self::assertCount(1, $this->sentTransactions());
    }

    public function testTheHttpStatusCodeGivesTheTransactionStatus(): void
    {
        $this->startRequestTransaction('get');
        \http_response_code(404);
        RequestTransaction::close();

        self::assertSame('not_found', (string) ($this->lastEvent()->getContexts()['trace']['status'] ?? ''));
    }

    public function testAnEarlierWarningIsNotTakenForAFatalError(): void
    {
        $this->startRequestTransaction('get');
        @\trigger_error('deprecated call', \E_USER_WARNING);
        RequestTransaction::close();

        self::assertSame('ok', (string) ($this->lastEvent()->getContexts()['trace']['status'] ?? ''));
    }

    /**
     * @return string[]
     */
    private function traceIdsInSendingOrder(): array
    {
        return \array_map(static function (Event $event): string {
            return (string) ($event->getContexts()['trace']['trace_id'] ?? '');
        }, CapturingTransport::$events);
    }
}
