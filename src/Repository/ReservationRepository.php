<?php

declare(strict_types=1);

/**
 * m4p_reservations
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace M4p_Reservations\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Data access layer for the reservations table.
 *
 * The expiry time (expires_at) is always computed by the database server (NOW()),
 * so the expires_at < NOW() comparison is consistent regardless of the PHP
 * time zone.
 */
final class ReservationRepository
{
    public const TABLE = 'm4p_reservation';

    private \Db $db;

    public function __construct()
    {
        $this->db = \Db::getInstance();
    }

    private function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    public function createTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (
            `id_reservation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_reservation`),
            UNIQUE KEY `cart_line` (`id_cart`, `id_product`, `id_product_attribute`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) $this->db->execute($sql);
    }

    public function dropTable(): bool
    {
        return (bool) $this->db->execute('DROP TABLE IF EXISTS `' . $this->table() . '`');
    }

    /**
     * Active reservations of a cart, keyed by "idProduct-idProductAttribute".
     *
     * @return array<string, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}>
     */
    public function getByCart(int $idCart): array
    {
        $rows = $this->getByCartRows($idCart);
        $indexed = [];
        foreach ($rows as $row) {
            $key = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    /**
     * @return array<int, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}>
     */
    public function getByCartRows(int $idCart): array
    {
        $sql = 'SELECT `id_reservation`, `id_product`, `id_product_attribute`, `id_shop`, `quantity`
            FROM `' . $this->table() . '`
            WHERE `id_cart` = ' . (int) $idCart;

        return $this->fetchRows($sql);
    }

    /**
     * Reservations that have already expired (expires_at < NOW()).
     *
     * @return array<int, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}>
     */
    public function getExpired(int $limit): array
    {
        $sql = 'SELECT `id_reservation`, `id_product`, `id_product_attribute`, `id_shop`, `quantity`
            FROM `' . $this->table() . '`
            WHERE `expires_at` < NOW()
            LIMIT ' . max(1, (int) $limit);

        return $this->fetchRows($sql);
    }

    /**
     * All reservations (used on uninstall - releasing the entire stock).
     *
     * @return array<int, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}>
     */
    public function getAll(int $limit): array
    {
        $sql = 'SELECT `id_reservation`, `id_product`, `id_product_attribute`, `id_shop`, `quantity`
            FROM `' . $this->table() . '`
            LIMIT ' . max(1, (int) $limit);

        return $this->fetchRows($sql);
    }

    /**
     * Sum of the active (not yet expired) reservations of a product,
     * grouped by combination. Used to reduce the displayed stock.
     *
     * @return array<int, int> [id_product_attribute => reserved_quantity]
     */
    public function getActiveReservedByProduct(int $idProduct): array
    {
        $sql = 'SELECT `id_product_attribute`, SUM(`quantity`) AS qty
            FROM `' . $this->table() . '`
            WHERE `id_product` = ' . (int) $idProduct . '
                AND `expires_at` >= NOW()
            GROUP BY `id_product_attribute`';

        $result = $this->db->executeS($sql);
        if (!is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result as $row) {
            $map[(int) $row['id_product_attribute']] = (int) $row['qty'];
        }

        return $map;
    }

    /**
     * Active (not yet expired) reservation of a single line of a cart.
     * Used to give a cart back its own reservation when computing
     * availability (compensation for the double stock subtraction).
     */
    public function getReservedForCartLine(int $idCart, int $idProduct, int $idProductAttribute): int
    {
        $sql = 'SELECT SUM(`quantity`)
            FROM `' . $this->table() . '`
            WHERE `id_cart` = ' . (int) $idCart . '
                AND `id_product` = ' . (int) $idProduct . '
                AND `id_product_attribute` = ' . (int) $idProductAttribute . '
                AND `expires_at` >= NOW()';

        return (int) $this->db->getValue($sql);
    }

    /**
     * Inserts or updates a cart-line reservation, setting expires_at to NOW() + ttl.
     */
    public function upsert(int $idCart, int $idProduct, int $idProductAttribute, int $idShop, int $quantity, int $ttlMinutes): bool
    {
        $expiry = 'DATE_ADD(NOW(), INTERVAL ' . max(1, (int) $ttlMinutes) . ' MINUTE)';

        $sql = 'INSERT INTO `' . $this->table() . '`
            (`id_cart`, `id_product`, `id_product_attribute`, `id_shop`, `quantity`, `expires_at`, `date_add`, `date_upd`)
            VALUES (
                ' . (int) $idCart . ',
                ' . (int) $idProduct . ',
                ' . (int) $idProductAttribute . ',
                ' . (int) $idShop . ',
                ' . (int) $quantity . ',
                ' . $expiry . ',
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `quantity` = ' . (int) $quantity . ',
                `id_shop` = ' . (int) $idShop . ',
                `expires_at` = ' . $expiry . ',
                `date_upd` = NOW()';

        return (bool) $this->db->execute($sql);
    }

    /**
     * Deletes a reservation by ID. Returns the number of deleted rows (0 or 1),
     * which lets the stock be safely released only once under concurrent requests.
     */
    public function deleteById(int $idReservation): int
    {
        $this->db->execute('DELETE FROM `' . $this->table() . '` WHERE `id_reservation` = ' . (int) $idReservation);

        return (int) $this->db->Affected_Rows();
    }

    /**
     * @return array<int, array{id_reservation:int,id_product:int,id_product_attribute:int,id_shop:int,quantity:int}>
     */
    private function fetchRows(string $sql): array
    {
        $result = $this->db->executeS($sql);
        if (!is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'id_reservation' => (int) $row['id_reservation'],
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'id_shop' => (int) $row['id_shop'],
                'quantity' => (int) $row['quantity'],
            ];
        }

        return $rows;
    }
}
