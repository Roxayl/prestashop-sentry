<?php

declare(strict_types=1);

// tests/vendor holds PHPUnit and the packages PrestaShop provides to the module at runtime;
// vendor holds the module's own dependencies, as in a shop.
foreach ([__DIR__ . '/vendor/autoload.php', \dirname(__DIR__) . '/vendor/autoload.php'] as $autoload) {
    if (!\file_exists($autoload)) {
        \fwrite(\STDERR, "Missing {$autoload}: run `make test-deps`, or `composer install` in the module and in tests/.\n");

        exit(1);
    }

    require_once $autoload;
}

require_once __DIR__ . '/Support/Context.php';
