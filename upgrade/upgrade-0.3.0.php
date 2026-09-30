<?php

declare(strict_types=1);

if (!\defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0(ExtSentry $module): bool
{
    $isEnabled = (bool) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $module->id
    );

    if (!$isEnabled) {
        return true;
    }

    try {
        $module->uninstallOverrides();
        $module->installOverrides();
    } catch (Throwable $ex) {
        PrestaShopLogger::addLog(
            '[extsentry] upgrade 0.3.0: the overrides could not be installed: ' . $ex->getMessage(),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
        );
    }

    return true;
}
