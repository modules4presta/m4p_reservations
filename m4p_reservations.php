<?php

declare(strict_types=1);

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

require_once __DIR__ . '/src/Config/Settings.php';
require_once __DIR__ . '/src/Repository/ReservationRepository.php';
require_once __DIR__ . '/src/Service/ReservationManager.php';

use M4p_Reservations\Config\Settings;
use M4p_Reservations\Repository\ReservationRepository;
use M4p_Reservations\Service\ReservationManager;

class M4p_Reservations extends Module
{
    private ?ReservationManager $manager = null;

    public function __construct()
    {
        $this->name = 'm4p_reservations';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Modules4Presta';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Cart stock reservation', [], 'Modules.M4preservations.Admin');
        $this->description = $this->trans('Holds the stock of everything in a cart for a set number of minutes, so two customers cannot buy the same last item.', [], 'Modules.M4preservations.Admin');
    }

    public function install(): bool
    {
        (new Settings())->applyDefaults();

        return parent::install()
            && (new ReservationRepository())->createTable()
            && $this->registerHook('actionCartSave')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionOverrideQuantityAvailableByProduct');
    }

    public function uninstall(): bool
    {
        // Release all reserved stock before dropping the table.
        $this->getManager()->releaseAll();

        $repository = new ReservationRepository();
        (new Settings())->deleteAll();

        return $repository->dropTable()
            && parent::uninstall();
    }

    /**
     * Map of the actively reserved quantities of a product per combination.
     * Exposed as an API for other modules (e.g. m4p_warehouses) so they
     * can reduce the displayed stock by ongoing reservations.
     *
     * @return array<int, int> [id_product_attribute => reserved_quantity]
     */
    public static function getReservedByAttribute(int $idProduct): array
    {
        if (!(bool) Configuration::get(Settings::CFG_ENABLED)) {
            return [];
        }

        return (new ReservationRepository())->getActiveReservedByProduct($idProduct);
    }

    private function getManager(): ReservationManager
    {
        if ($this->manager === null) {
            $this->manager = new ReservationManager(new Settings(), new ReservationRepository());
        }

        return $this->manager;
    }

    /**
     * After every cart change: reconcile reservations and refresh the expiry time.
     */
    public function hookActionCartSave(array $params): void
    {
        if (empty($params['cart']) || !($params['cart'] instanceof Cart)) {
            return;
        }

        $this->getManager()->reconcileCart($params['cart']);
    }

    /**
     * After an order is placed we release the reservations - the order itself already removes the stock.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!empty($params['cart']) && $params['cart'] instanceof Cart) {
            $this->getManager()->releaseCart((int) $params['cart']->id);

            return;
        }

        if (!empty($params['order']) && $params['order'] instanceof Order) {
            $this->getManager()->releaseCart((int) $params['order']->id_cart);
        }
    }

    /**
     * On every page refresh by a customer we check expired reservations
     * and release their stock (no cron needed).
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $this->getManager()->expireDue();
    }

    /**
     * Compensation for the double stock subtraction.
     *
     * A reservation removes the customer's own cart from the global StockAvailable, and
     * PrestaShop, in its availability checks (Product::getQuantity -> this function),
     * subtracts the same quantity once more. Without this a logged-in customer could buy
     * at most ~half of the real stock and got a false "out of stock" popup exactly
     * at the stock boundary. We give the cart back its own reservation, so from its
     * perspective availability is reduced only by OTHER customers' reservations.
     *
     * Protection against overselling between customers stays intact:
     * in another customer's context this cart has no reservation, so we return null
     * and the core computes the raw (reservation-reduced) stock as before.
     *
     * @param array<string, mixed> $params
     *
     * @return int|null int = overridden stock; null = the core computes normally
     */
    public function hookActionOverrideQuantityAvailableByProduct(array $params)
    {
        if (defined('_PS_ADMIN_DIR_') || !(bool) Configuration::get(Settings::CFG_ENABLED)) {
            return null;
        }

        $context = Context::getContext();
        if (!$context instanceof Context
            || !$context->customer instanceof Customer || (int) $context->customer->id <= 0
            || !$context->cart instanceof Cart || (int) $context->cart->id <= 0
        ) {
            return null;
        }

        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($idProduct <= 0) {
            return null;
        }
        // null = product without a combination; the core maps it to the id_product_attribute = 0 row.
        $idAttribute = ($params['id_product_attribute'] ?? null) === null ? 0 : (int) $params['id_product_attribute'];

        $reserved = (new ReservationRepository())->getReservedForCartLine(
            (int) $context->cart->id,
            $idProduct,
            $idAttribute
        );
        if ($reserved <= 0) {
            // This cart reserved nothing on this line -> the core computes as usual.
            return null;
        }

        return $this->getRawStockAvailable($idProduct, $idAttribute, $params['id_shop'] ?? null) + $reserved;
    }

    /**
     * Raw quantity from ps_stock_available, computed exactly like core
     * StockAvailable::getQuantityAvailableByProduct but WITHOUT its hook (no recursion).
     */
    private function getRawStockAvailable(int $idProduct, int $idAttribute, $idShop): int
    {
        $query = new DbQuery();
        $query->select('SUM(quantity)');
        $query->from('stock_available');
        $query->where('id_product = ' . (int) $idProduct);
        $query->where('id_product_attribute = ' . (int) $idAttribute);
        $query = StockAvailable::addSqlShopRestriction($query, $idShop === null ? null : (int) $idShop);

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitM4pReservations')) {
            Configuration::updateValue(Settings::CFG_ENABLED, (int) (bool) Tools::getValue(Settings::CFG_ENABLED));

            $ttl = (int) Tools::getValue(Settings::CFG_TTL_MIN);
            if ($ttl < 1) {
                $ttl = Settings::DEFAULT_TTL_MIN;
            }
            Configuration::updateValue(Settings::CFG_TTL_MIN, $ttl);

            $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.M4preservations.Admin'));
        }

        return $output . $this->renderConfigForm();
    }

    private function renderConfigForm(): string
    {
        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->trans('Reservation settings', [], 'Modules.M4preservations.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Reserve stock held in carts', [], 'Modules.M4preservations.Admin'),
                        'name' => Settings::CFG_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'res_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.M4preservations.Admin')],
                            ['id' => 'res_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.M4preservations.Admin')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('How long stock stays reserved', [], 'Modules.M4preservations.Admin'),
                        'name' => Settings::CFG_TTL_MIN,
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->trans('minutes', [], 'Modules.M4preservations.Admin'),
                        'desc' => $this->trans('Counted from the last change to the cart. When it runs out, the stock goes back on sale.', [], 'Modules.M4preservations.Admin'),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Modules.M4preservations.Admin'), 'name' => 'submitM4pReservations'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitM4pReservations';
        $helper->fields_value = [
            Settings::CFG_ENABLED => (int) Configuration::get(Settings::CFG_ENABLED),
            Settings::CFG_TTL_MIN => (int) Configuration::get(Settings::CFG_TTL_MIN) ?: Settings::DEFAULT_TTL_MIN,
        ];

        return $helper->generateForm([$fields_form]);
    }
}
