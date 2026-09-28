<?php

declare(strict_types=1);

/**
 * m4p_reservations
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace M4p_Reservations\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's settings: the Configuration keys and typed getters for them.
 */
final class Settings
{
    public const CFG_ENABLED = 'M4P_RES_ENABLED';
    public const CFG_TTL_MIN = 'M4P_RES_TTL_MIN';

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_TTL_MIN = 15;

    public function isEnabled(): bool
    {
        return (bool) \Configuration::get(self::CFG_ENABLED);
    }

    /**
     * How long a reservation lasts, in minutes; never below 1.
     */
    public function getTtlMinutes(): int
    {
        $ttl = (int) \Configuration::get(self::CFG_TTL_MIN);

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_MIN;
    }

    /**
     * Wartosci domyslne zapisywane przy instalacji.
     */
    public function applyDefaults(): void
    {
        \Configuration::updateValue(self::CFG_ENABLED, self::DEFAULT_ENABLED ? 1 : 0);
        \Configuration::updateValue(self::CFG_TTL_MIN, self::DEFAULT_TTL_MIN);
    }

    public function deleteAll(): void
    {
        \Configuration::deleteByName(self::CFG_ENABLED);
        \Configuration::deleteByName(self::CFG_TTL_MIN);
    }
}
