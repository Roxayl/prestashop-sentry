<?php

declare(strict_types=1);

// Stands in for the PrestaShop core class that the Hook override extends.
class HookCore
{
    /**
     * @return mixed
     */
    public static function coreCallHook($module, $method, $params)
    {
        return $module->{$method}($params);
    }
}
