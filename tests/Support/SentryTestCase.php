<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Support;

use Extalion\Sentry\Tracing\RequestTransaction;
use Extalion\Sentry\Tracing\Tracer;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

abstract class SentryTestCase extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        \Context::getContext()->controller = null;
        $this->bindCapturingClient([
            'dsn' => 'https://public@example.ingest.sentry.io/1',
            'traces_sample_rate' => 1.0,
            'default_integrations' => false,
        ]);
    }

    protected function tearDown(): void
    {
        RequestTransaction::close();
        Tracer::deactivate();
        $_SERVER = $this->server;
        \http_response_code(200);
        \error_clear_last();
    }

    /**
     * Binds a client built from $options whose transport captures events instead of sending them.
     *
     * @param array<string, mixed> $options
     */
    protected function bindCapturingClient(array $options): void
    {
        CapturingTransport::$events = [];
        $client = ClientBuilder::create($options)
            ->setTransportFactory(new class() implements TransportFactoryInterface {
                public function create(Options $options): TransportInterface
                {
                    return new CapturingTransport();
                }
            })
            ->getClient();
        SentrySdk::init()->bindClient($client);
    }

    protected function lastEvent(): Event
    {
        $event = \end(CapturingTransport::$events);
        self::assertInstanceOf(Event::class, $event, 'No event was sent.');

        return $event;
    }

    /**
     * @return Event[] the transactions sent, without the error events
     */
    protected function sentTransactions(): array
    {
        return \array_values(\array_filter(CapturingTransport::$events, static function (Event $event): bool {
            return (string) $event->getType() === 'transaction';
        }));
    }

    protected function startRequestTransaction(string $method = 'post'): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        RequestTransaction::start(1.0, 'apache2handler');
    }
}
