<?php

declare(strict_types=1);

// Stands in for the PrestaShop Module class; addOverride() returns or throws what each test sets.
class Module
{
    /** @var array<string, bool|\Throwable> */
    public static array $overrideOutcomes = [];

    /** @var string[] */
    public array $attemptedOverrides = [];

    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $bootstrap;
    public $displayName;
    public $description;
    public $confirmUninstall;
    public $ps_versions_compliancy;
    public $_errors = [];

    public function __construct()
    {
    }

    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        return $id;
    }

    public function addOverride($classname)
    {
        $this->attemptedOverrides[] = $classname;
        $outcome = self::$overrideOutcomes[$classname] ?? true;

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
