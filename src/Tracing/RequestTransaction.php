<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tracing;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

final class RequestTransaction
{
    private const FATAL_ERRORS = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

    private static ?Transaction $transaction = null;
    private static bool $namedByRoute = false;

    public static function start(?float $sampleRate, string $sapi = \PHP_SAPI): void
    {
        if ($sapi === 'cli' || $sampleRate === null || $sampleRate <= 0.0) {
            return;
        }

        try {
            $client = SentrySdk::getCurrentHub()->getClient();

            if ($client === null || $client->getOptions()->getDsn() === null) {
                return;
            }

            $context = new TransactionContext();
            $context->setOp('http.server');
            $context->setName(self::method() . ' ' . ($_SERVER['SCRIPT_NAME'] ?? '/'));
            $context->setSource(TransactionSource::route());
            $context->setStartTimestamp((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true)));

            $transaction = \Sentry\startTransaction($context);

            if (!$transaction->getSampled()) {
                return;
            }

            SentrySdk::getCurrentHub()->setSpan($transaction);
            self::$transaction = $transaction;
            self::$namedByRoute = false;
            Tracer::activate($transaction);

            // Registered from a shutdown function to run after those registered during the request.
            \register_shutdown_function(static function (): void {
                \register_shutdown_function([self::class, 'close']);
            });
        } catch (\Throwable $ex) {
            \error_log('[extsentry] Unable to start the request transaction: ' . $ex->getMessage());
        }
    }

    public static function nameFromRoute(string $route): void
    {
        if (self::$transaction === null) {
            return;
        }

        self::$transaction->setName(self::method() . ' ' . $route);
        self::$transaction->getMetadata()->setSource(TransactionSource::route());
        self::$namedByRoute = true;
    }

    public static function close(): void
    {
        $transaction = self::$transaction;

        if ($transaction === null) {
            return;
        }

        self::$transaction = null;

        try {
            $end = \microtime(true);
            Tracer::closeOpenSpans($end);

            if (!self::$namedByRoute) {
                self::nameFromController($transaction);
            }

            self::setStatus($transaction);
            $transaction->setData(Tracer::summary());
            $transaction->finish($end);
        } catch (\Throwable $ex) {
            \error_log('[extsentry] Unable to send the request transaction: ' . $ex->getMessage());
        } finally {
            Tracer::deactivate();
            SentrySdk::getCurrentHub()->setSpan(null);
        }
    }

    private static function nameFromController(Transaction $transaction): void
    {
        if (!\class_exists('Context', false)) {
            return;
        }

        $controller = \Context::getContext()->controller ?? null;

        if (!\is_object($controller)) {
            return;
        }

        $name = self::method() . ' ' . \get_class($controller);

        if (!empty($controller->ajax)) {
            $name .= ' (ajax)';
        }

        $transaction->setName($name);
        $transaction->getMetadata()->setSource(TransactionSource::component());
    }

    private static function setStatus(Transaction $transaction): void
    {
        $error = \error_get_last();

        if ($error !== null && ($error['type'] & self::FATAL_ERRORS) !== 0) {
            $transaction->setStatus(SpanStatus::internalError());

            return;
        }

        $statusCode = \http_response_code();

        if (\is_int($statusCode)) {
            $transaction->setHttpStatus($statusCode);
        }
    }

    private static function method(): string
    {
        return \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}
