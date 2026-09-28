<?php

declare(strict_types=1);

/**
 * m4p_reservations
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace M4p_Reservations\Service;

use M4p_Reservations\Config\Settings;
use M4p_Reservations\Repository\ReservationRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The reservation engine.
 *
 * A reservation takes stock off StockAvailable as a delta, and expiry, removing
 * the cart line or placing the order gives the same number back. That keeps
 * ps_stock_available consistent with the warehouse module, which writes the sum
 * of its warehouses there, with the core's own add-to-cart limits and with every
 * view of availability.
 */
final class ReservationManager
{
    private Settings $settings;
    private ReservationRepository $repository;

    public function __construct(Settings $settings, ReservationRepository $repository)
    {
        $this->settings = $settings;
        $this->repository = $repository;
    }

    /**
     * Reconciles reservations with the current cart contents:
     * - applies a stock delta to products whose quantity changed,
     * - releases stock for lines removed from the cart,
     * - refreshes the whole cart's expiry time (timer from the last change).
     */
    public function reconcileCart(\Cart $cart): void
    {
        if (!$this->settings->isEnabled()) {
            return;
        }
        if (!\Validate::isLoadedObject($cart) || (int) $cart->id <= 0) {
            return;
        }
        // B2B shop: reserve only carts assigned to a logged-in customer.
        if ((int) $cart->id_customer <= 0) {
            return;
        }

        $idCart = (int) $cart->id;

        // Cart turned into an order - release reservations, the order already removes the stock.
        if ($cart->orderExists()) {
            $this->releaseCart($idCart);

            return;
        }

        $idShop = (int) $cart->id_shop ?: (int) \Context::getContext()->shop->id;
        $ttl = $this->settings->getTtlMinutes();

        // Read products from a dedicated Cart instance so we never poison the
        // shared context cart's getProducts() cache. reconcileCart runs on
        // actionCartSave; caching an early or empty product list on the context
        // cart makes a later Cart::getOrderTotal() return 0 while the page is
        // rendering, which shows the customer a cart worth nothing.
        $current = [];
        $productReader = new \Cart($idCart);
        foreach ($productReader->getProducts(true) as $product) {
            $idProduct = (int) $product['id_product'];
            $idAttribute = (int) $product['id_product_attribute'];
            $key = $idProduct . '-' . $idAttribute;
            $current[$key] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttribute,
                'quantity' => (int) $product['cart_quantity'],
            ];
        }

        $existing = $this->repository->getByCart($idCart);

        // Lines removed from the cart -> release stock.
        foreach ($existing as $key => $row) {
            if (!isset($current[$key])) {
                $this->adjustStock($row['id_product'], $row['id_product_attribute'], $row['quantity'], $row['id_shop']);
                $this->repository->deleteById($row['id_reservation']);
            }
        }

        // Current lines -> delta + upsert (refreshing expires_at).
        foreach ($current as $key => $line) {
            $existingQty = isset($existing[$key]) ? $existing[$key]['quantity'] : 0;
            $desired = $line['quantity'];

            // Positive delta = how much to return to stock; negative = how much to remove.
            $stockDelta = $existingQty - $desired;
            if ($stockDelta !== 0) {
                $this->adjustStock($line['id_product'], $line['id_product_attribute'], $stockDelta, $idShop);
            }

            $this->repository->upsert($idCart, $line['id_product'], $line['id_product_attribute'], $idShop, $desired, $ttl);
        }
    }

    /**
     * Gives back the stock of expired reservations. Called on every front office
     * page, so the stock returns without a cron job.
     */
    public function expireDue(int $limit = 500): void
    {
        $this->restoreAndDelete($this->repository->getExpired($limit));
    }

    /**
     * Releases every reservation of a cart, for instance once its order is placed.
     */
    public function releaseCart(int $idCart): void
    {
        $this->restoreAndDelete($this->repository->getByCartRows($idCart));
    }

    /**
     * Gives back all reserved stock, used when the module is uninstalled.
     */
    public function releaseAll(): void
    {
        do {
            $rows = $this->repository->getAll(500);
            $this->restoreAndDelete($rows);
        } while (count($rows) > 0);
    }

    /**
     * @param array<int, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}> $rows
     */
    private function restoreAndDelete(array $rows): void
    {
        foreach ($rows as $row) {
            // Najpierw usun (z kontrola liczby wierszy), potem zwroc stan - chroni
            // przed podwojnym zwrotem przy rownoleglych zadaniach.
            if ($this->repository->deleteById($row['id_reservation']) > 0) {
                $this->adjustStock($row['id_product'], $row['id_product_attribute'], $row['quantity'], $row['id_shop']);
            }
        }
    }

    /**
     * Applies a delta to available stock. Delta > 0 returns stock, delta < 0 removes it.
     */
    private function adjustStock(int $idProduct, int $idProductAttribute, int $delta, int $idShop): void
    {
        if ($delta === 0) {
            return;
        }

        \StockAvailable::updateQuantity($idProduct, $idProductAttribute, $delta, $idShop);
    }
}
