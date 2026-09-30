<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Tests\Support\FaultySpan;
use Extalion\Sentry\Tests\Support\SentryTestCase;
use Extalion\Sentry\Tracing\Tracer;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class TracerTest extends SentryTestCase
{
    private const EVERYTHING_SLOW = 0;
    private const NOTHING_SLOW = \PHP_INT_MAX;

    public function testAnInactiveTracerOnlyRunsTheCallback(): void
    {
        Tracer::deactivate();

        self::assertSame(7, Tracer::traceQuery('SELECT 1', static function () {
            return 7;
        }));
        self::assertSame(8, Tracer::traceHook('mod::hook', static function () {
            return 8;
        }));
    }

    public function testFastQueriesAreCountedAndAggregatedWithoutSpans(): void
    {
        $transaction = $this->startTransaction(self::NOTHING_SLOW);

        self::assertSame('result', Tracer::traceQuery('SELECT 1', static function () {
            return 'result';
        }));
        Tracer::traceQuery('SELECT 2', static function () {
            return [];
        });
        $summary = Tracer::summary();

        self::assertSame(2, $summary['db.query_count']);
        self::assertStringStartsWith('2 x ', $summary['db.top'][0]);
        self::assertStringEndsWith('SELECT ?', $summary['db.top'][0]);
        self::assertSame([], $this->spansOf($transaction));
    }

    public function testASlowQueryGetsANormalizedSpanUnderTheTransaction(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);

        Tracer::traceQuery("SELECT * FROM a WHERE email = 'x@example.com'", static function () {
            return [];
        });
        $spans = $this->spansOf($transaction);

        self::assertArrayHasKey('SELECT * FROM a WHERE email = ?', $spans);
        $span = $spans['SELECT * FROM a WHERE email = ?'];
        self::assertSame('db.sql.query', $span->getOp());
        self::assertSame('mysql', $span->getData()['db.system'] ?? null);
        self::assertSame((string) $transaction->getSpanId(), (string) $span->getParentSpanId());
    }

    public function testFastHooksLeaveNoSpanButAreCountedAndAggregated(): void
    {
        $transaction = $this->startTransaction(self::NOTHING_SLOW);

        Tracer::traceHook('mod::hookA', static function () {
            return null;
        });
        Tracer::traceHook('mod::hookA', static function () {
            return null;
        });
        $summary = Tracer::summary();

        self::assertSame(2, $summary['hooks.count']);
        self::assertStringStartsWith('2 x ', $summary['hooks.top'][0]);
        self::assertStringEndsWith('mod::hookA', $summary['hooks.top'][0]);
        self::assertSame([], $this->spansOf($transaction));
    }

    public function testASlowHookGetsASpanThatStartsWithTheHook(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);
        $before = \microtime(true);

        Tracer::traceHook('mod::hookSlow', static function (): void {
        });
        $after = \microtime(true);
        $spans = $this->spansOf($transaction);

        self::assertArrayHasKey('mod::hookSlow', $spans);
        $span = $spans['mod::hookSlow'];
        self::assertSame('hook', $span->getOp());
        self::assertGreaterThanOrEqual($before, $span->getStartTimestamp());
        self::assertLessThanOrEqual($span->getEndTimestamp(), $span->getStartTimestamp());
        self::assertLessThanOrEqual($after, $span->getEndTimestamp());
    }

    public function testAHookLastingOneAndAHalfMillisecondsCrossesTheDefaultThreshold(): void
    {
        $transaction = $this->startTransaction();

        Tracer::traceHook('mod::hookSlow', static function (): void {
            \usleep(1500);
        });

        self::assertArrayHasKey('mod::hookSlow', $this->spansOf($transaction));
    }

    public function testAQueryInsideHooksGetsTheParentChainBeforeTheHooksEnd(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);

        Tracer::traceHook('mod::outer', static function (): void {
            Tracer::traceHook('mod::inner', static function (): void {
                Tracer::traceQuery('SELECT 2 FROM a', static function () {
                    return [];
                });
                Tracer::traceQuery('SELECT 3 FROM b', static function () {
                    return [];
                });
            });
        });
        $spans = $this->spansOf($transaction);
        $descriptions = \array_keys($spans);
        \sort($descriptions);

        self::assertSame(['SELECT ? FROM a', 'SELECT ? FROM b', 'mod::inner', 'mod::outer'], $descriptions);
        self::assertSame((string) $transaction->getSpanId(), (string) $spans['mod::outer']->getParentSpanId());
        self::assertSame((string) $spans['mod::outer']->getSpanId(), (string) $spans['mod::inner']->getParentSpanId());
        self::assertSame((string) $spans['mod::inner']->getSpanId(), (string) $spans['SELECT ? FROM a']->getParentSpanId());
        self::assertSame((string) $spans['mod::inner']->getSpanId(), (string) $spans['SELECT ? FROM b']->getParentSpanId());
        self::assertSame(2, $spans['mod::outer']->getData()['db.query_count'] ?? null);
    }

    public function testAFailedQueryIsCountedFlaggedAndNeverRunTwice(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);
        $runs = 0;

        Tracer::traceQuery('UPDATE a SET b = 1', static function () use (&$runs) {
            ++$runs;

            return false;
        });

        try {
            Tracer::traceQuery('SELECT boom', static function () use (&$runs): void {
                ++$runs;

                throw new \RuntimeException('boom');
            });
            self::fail('The query exception must be rethrown.');
        } catch (\RuntimeException $ex) {
            self::assertSame('boom', $ex->getMessage());
        }

        self::assertSame(2, $runs);
        self::assertSame(2, Tracer::summary()['db.error_count']);
        $spans = $this->spansOf($transaction);
        self::assertSame('internal_error', (string) $spans['UPDATE a SET b = ?']->getStatus());
        self::assertSame('internal_error', (string) $spans['SELECT boom']->getStatus());
    }

    public function testSpansStopAtTheCapAndTheOverflowIsReported(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW, 2);

        for ($i = 0; $i < 3; ++$i) {
            Tracer::traceQuery('SELECT ' . $i . ' FROM t' . $i, static function () {
                return [];
            });
        }

        self::assertSame(1, Tracer::summary()['spans.dropped'] ?? null);
        self::assertCount(2, $this->spansOf($transaction));
    }

    public function testASlowHookInterruptedBeforeItHasASpanStillGetsOne(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);
        $end = 0.0;

        Tracer::traceHook('mod::exits', static function () use (&$end): void {
            $end = \microtime(true);
            Tracer::closeOpenSpans($end);
        });
        $summary = Tracer::summary();
        $spans = $this->spansOf($transaction);

        self::assertSame(1, $summary['hooks.count']);
        self::assertStringEndsWith('mod::exits', $summary['hooks.top'][0]);
        self::assertArrayHasKey('mod::exits', $spans);
        self::assertSame($end, $spans['mod::exits']->getEndTimestamp());
    }

    public function testOpenHookSpansAreClosedAtTheGivenTimeWithTheirQueries(): void
    {
        $transaction = $this->startTransaction(self::EVERYTHING_SLOW);
        $end = 0.0;

        Tracer::traceHook('mod::redirects', static function () use (&$end): void {
            Tracer::traceQuery('SELECT 1', static function () {
                return [];
            });
            $end = \microtime(true);
            Tracer::closeOpenSpans($end);
        });
        $span = $this->spansOf($transaction)['mod::redirects'];

        self::assertSame($end, $span->getEndTimestamp());
        self::assertSame(1, $span->getData()['db.query_count'] ?? null);
    }

    public function testAnInternalFailureStopsTracingAndClosesTheOpenHookSpans(): void
    {
        $context = new SpanContext();
        $context->setSampled(true);
        FaultySpan::$failingOp = 'db.sql.query';
        FaultySpan::$children = [];
        Tracer::activate(new FaultySpan($context), Tracer::MAX_SPANS, self::EVERYTHING_SLOW);
        $errorLog = \ini_set('error_log', '/dev/null');

        try {
            $result = Tracer::traceHook('mod::outer', static function () {
                return Tracer::traceQuery('SELECT 1', static function () {
                    return 'rows';
                });
            });
        } finally {
            \ini_set('error_log', (string) $errorLog);
        }

        self::assertSame('rows', $result);
        self::assertFalse(Tracer::isActive());
        self::assertCount(1, FaultySpan::$children);
        self::assertSame('mod::outer', FaultySpan::$children[0]->getDescription());
        self::assertNotNull(FaultySpan::$children[0]->getEndTimestamp());
    }

    private function startTransaction(int $slowNs = Tracer::SLOW_NS, int $maxSpans = Tracer::MAX_SPANS): Transaction
    {
        $context = new TransactionContext();
        $context->setName('test');
        $context->setOp('http.server');
        $transaction = \Sentry\startTransaction($context);
        Tracer::activate($transaction, $maxSpans, $slowNs);

        return $transaction;
    }

    /**
     * @return array<string, Span> spans indexed by description
     */
    private function spansOf(Transaction $transaction): array
    {
        Tracer::deactivate();
        $transaction->finish();
        $spans = [];

        foreach ($this->lastEvent()->getSpans() as $span) {
            $spans[(string) $span->getDescription()] = $span;
        }

        return $spans;
    }
}
