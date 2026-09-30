<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Support;

final class FakeModule
{
    public string $name = 'fakemodule';

    /**
     * @param array<string, mixed> $params
     */
    public function hookDisplayHome(array $params): string
    {
        return 'home for ' . $params['customer'];
    }
}
