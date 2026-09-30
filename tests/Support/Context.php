<?php

declare(strict_types=1);

// Stands in for the PrestaShop Context class, which RequestTransaction reads the current controller from.
final class Context
{
    /** @var object|null */
    public $controller;

    private static ?self $instance = null;

    public static function getContext(): self
    {
        return self::$instance ?? (self::$instance = new self());
    }
}
