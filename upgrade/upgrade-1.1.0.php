<?php

/**
 * m4p_reservations
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Cart override giving a cart line back its own reservation comes with the module.
 */
function upgrade_module_1_1_0(M4p_Reservations $module): bool
{
    return $module->installOverrides();
}
