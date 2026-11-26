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
 * A deleted cart gives its reserved stock back at once.
 */
function upgrade_module_1_2_0(M4p_Reservations $module): bool
{
    return $module->registerHook('actionObjectCartDeleteAfter');
}
