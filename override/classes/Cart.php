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

class Cart extends CartCore
{
    /**
     * Compensation for the double stock subtraction done by the m4p_reservations module.
     *
     * A cart line's stock_quantity comes straight from ps_stock_available (Cart.php),
     * which the reservations module has already reduced by this customer's own cart. The core
     * then compares stock_quantity with the quantity in the cart, so without a correction the line
     * would be blocked at ~half of the real stock (and JOM "+" buttons would "revert" the quantity).
     * We give the line back its own reservation - from the customer's perspective the stock
     * is reduced only by other customers' reservations. The twin fix on the availability-check
     * side: M4p_Reservations::hookActionOverrideQuantityAvailableByProduct.
     */
    protected function applyProductCalculations($row, $shopContext, $productQuantity = null, bool $keepOrderPrices = false)
    {
        $row = parent::applyProductCalculations($row, $shopContext, $productQuantity, $keepOrderPrices);

        try {
            if (!defined('_PS_ADMIN_DIR_')
                && (int) $this->id > 0
                && isset($row['id_product'], $row['id_product_attribute'], $row['stock_quantity'])
                && (bool) Configuration::get('M4P_RES_ENABLED')
            ) {
                $reserved = (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                    'SELECT SUM(`quantity`) FROM `' . _DB_PREFIX_ . 'm4p_reservation`
                        WHERE `id_cart` = ' . (int) $this->id . '
                            AND `id_product` = ' . (int) $row['id_product'] . '
                            AND `id_product_attribute` = ' . (int) $row['id_product_attribute'] . '
                            AND `expires_at` >= NOW()'
                );

                if ($reserved > 0) {
                    $row['stock_quantity'] = (int) $row['stock_quantity'] + $reserved;
                }
            }
        } catch (\Throwable $e) {
            // The compensation must not break the cart.
        }

        return $row;
    }
}
