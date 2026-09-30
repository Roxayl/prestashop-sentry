<?php

declare(strict_types=1);

// Stands in for the PrestaShop Db class; getValue() returns what each test sets.
class Db
{
    /** @var mixed */
    public static $value = false;

    public static function getInstance(): self
    {
        return new self();
    }

    /**
     * @return mixed
     */
    public function getValue($sql)
    {
        return self::$value;
    }
}
