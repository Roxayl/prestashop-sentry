<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tracing;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

final class Tracer
{
    public const SLOW_NS = 1000000;
    public const MAX_SPANS = 999;

    private const TOP_SIZE = 15;
    private const TOP_KEY_BYTES = 200;

    private static bool $active = false;
    private static ?Span $root = null;
    private static int $maxSpans = self::MAX_SPANS;
    private static int $slowNs = self::SLOW_NS;

    /** @var list<array{description: string, start: float, t0: int, span: ?Span, queries: int, ns: int}> */
    private static array $frames = [];

    /** @var array<string, array{0: int, 1: int}> */
    private static array $hookTotals = [];

    /** @var array<string, array{0: int, 1: int}> */
    private static array $queryTotals = [];

    private static int $queryCount = 0;
    private static int $queryNs = 0;
    private static int $queryErrors = 0;
    private static int $hookCount = 0;
    private static int $spanCount = 0;
    private static int $droppedSpans = 0;

    public static function activate(Span $root, int $maxSpans = self::MAX_SPANS, int $slowNs = self::SLOW_NS): void
    {
        self::$frames = [];
        self::$hookTotals = [];
        self::$queryTotals = [];
        self::$queryCount = 0;
        self::$queryNs = 0;
        self::$queryErrors = 0;
        self::$hookCount = 0;
        self::$spanCount = 0;
        self::$droppedSpans = 0;
        self::$maxSpans = $maxSpans;
        self::$slowNs = $slowNs;
        self::$root = $root;
        self::$active = true;
    }

    public static function deactivate(): void
    {
        self::$active = false;
        self::$root = null;
        self::$frames = [];
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * @return mixed the result of $query
     */
    public static function traceQuery(string $sql, callable $query)
    {
        $start = \microtime(true);
        $t0 = \hrtime(true);

        try {
            $result = $query();
        } catch (\Throwable $ex) {
            self::recordQuery($sql, $start, \hrtime(true) - $t0, true);

            throw $ex;
        }

        self::recordQuery($sql, $start, \hrtime(true) - $t0, $result === false);

        return $result;
    }

    /**
     * @return mixed the result of $call
     */
    public static function traceHook(string $description, callable $call)
    {
        if (!self::$active) {
            return $call();
        }

        self::$frames[] = [
            'description' => $description,
            'start' => \microtime(true),
            't0' => \hrtime(true),
            'span' => null,
            'queries' => 0,
            'ns' => 0,
        ];

        try {
            return $call();
        } finally {
            self::recordHook();
        }
    }

    public static function closeOpenSpans(float $end): void
    {
        $now = \hrtime(true);
        $parent = self::$root;
        $open = [];

        foreach (self::$frames as $frame) {
            $ns = $now - $frame['t0'];
            ++self::$hookCount;
            self::addTotal(self::$hookTotals, $frame['description'], $ns);
            $span = $frame['span'];

            if ($span === null && $parent !== null && $ns >= self::$slowNs) {
                $span = self::startSpan('hook', $frame['description'], $frame['start'], $parent);
            }

            if ($span === null) {
                continue;
            }

            if ($frame['queries'] > 0) {
                $span->setData([
                    'db.query_count' => $frame['queries'],
                    'db.duration_ms' => \round($frame['ns'] / 1e6, 2),
                ]);
            }

            $parent = $span;
            $open[] = $span;
        }

        foreach (\array_reverse($open) as $span) {
            $span->finish($end);
        }

        self::$frames = [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        $summary = [
            'db.query_count' => self::$queryCount,
            'db.duration_ms' => \round(self::$queryNs / 1e6, 1),
            'db.error_count' => self::$queryErrors,
            'hooks.count' => self::$hookCount,
            'hooks.top' => self::top(self::$hookTotals),
            'db.top' => self::top(self::$queryTotals),
        ];

        if (self::$droppedSpans > 0) {
            $summary['spans.dropped'] = self::$droppedSpans;
        }

        return $summary;
    }

    private static function recordQuery(string $sql, float $start, int $ns, bool $failed): void
    {
        if (!self::$active) {
            return;
        }

        try {
            ++self::$queryCount;
            self::$queryNs += $ns;

            if ($failed) {
                ++self::$queryErrors;
            }

            foreach (\array_keys(self::$frames) as $i) {
                ++self::$frames[$i]['queries'];
                self::$frames[$i]['ns'] += $ns;
            }

            $shape = SqlNormalizer::normalize($sql);
            self::addTotal(self::$queryTotals, $shape, $ns);

            if ($ns < self::$slowNs) {
                return;
            }

            $span = self::startSpan('db.sql.query', $shape, $start, self::parentSpan());

            if ($span === null) {
                return;
            }

            $span->setData(['db.system' => 'mysql']);

            if ($failed) {
                $span->setStatus(SpanStatus::internalError());
            }

            $span->finish($start + $ns / 1e9);
        } catch (\Throwable $ex) {
            self::fail($ex);
        }
    }

    private static function recordHook(): void
    {
        if (!self::$active || self::$frames === []) {
            return;
        }

        try {
            $frame = \array_pop(self::$frames);
            $ns = \hrtime(true) - $frame['t0'];
            ++self::$hookCount;
            self::addTotal(self::$hookTotals, $frame['description'], $ns);
            $span = $frame['span'];

            if ($span === null && $ns >= self::$slowNs) {
                $span = self::startSpan('hook', $frame['description'], $frame['start'], self::parentSpan());
            }

            if ($span === null) {
                return;
            }

            if ($frame['queries'] > 0) {
                $span->setData([
                    'db.query_count' => $frame['queries'],
                    'db.duration_ms' => \round($frame['ns'] / 1e6, 2),
                ]);
            }

            $span->finish($frame['start'] + $ns / 1e9);
        } catch (\Throwable $ex) {
            self::fail($ex);
        }
    }

    /**
     * Gives a span to every running hook that has none yet, oldest first, and returns the innermost.
     */
    private static function parentSpan(): Span
    {
        $parent = self::$root;

        if ($parent === null) {
            throw new \LogicException('The tracer has no root span.');
        }

        foreach (\array_keys(self::$frames) as $i) {
            if (self::$frames[$i]['span'] === null) {
                $span = self::startSpan('hook', self::$frames[$i]['description'], self::$frames[$i]['start'], $parent);

                if ($span === null) {
                    return $parent;
                }

                self::$frames[$i]['span'] = $span;
            }

            $parent = self::$frames[$i]['span'];
        }

        return $parent;
    }

    private static function startSpan(string $op, string $description, float $start, Span $parent): ?Span
    {
        if (self::$spanCount >= self::$maxSpans) {
            ++self::$droppedSpans;

            return null;
        }

        ++self::$spanCount;
        $context = new SpanContext();
        $context->setOp($op);
        $context->setDescription($description);
        $context->setStartTimestamp($start);

        return $parent->startChild($context);
    }

    /**
     * @param array<string, array{0: int, 1: int}> $totals
     */
    private static function addTotal(array &$totals, string $key, int $ns): void
    {
        $total = $totals[$key] ?? [0, 0];
        $totals[$key] = [$total[0] + 1, $total[1] + $ns];
    }

    /**
     * @param array<string, array{0: int, 1: int}> $totals
     *
     * @return string[]
     */
    private static function top(array $totals): array
    {
        \uasort($totals, static function (array $a, array $b): int {
            return $b[1] <=> $a[1];
        });
        $lines = [];

        foreach (\array_slice($totals, 0, self::TOP_SIZE, true) as $key => $total) {
            $lines[] = \sprintf('%d x %.1f ms %s', $total[0], $total[1] / 1e6, \mb_strcut((string) $key, 0, self::TOP_KEY_BYTES, 'UTF-8'));
        }

        return $lines;
    }

    private static function fail(\Throwable $ex): void
    {
        \error_log('[extsentry] Tracing stopped for this request: ' . $ex->getMessage());

        try {
            self::closeOpenSpans(\microtime(true));
        } catch (\Throwable $closeFailure) {
        }

        self::deactivate();
    }
}
