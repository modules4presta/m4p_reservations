# M4P Cart Stock Reservation for PrestaShop 8 & 9

**Stop selling the same last item twice — stock in a cart is held for a few minutes, and released the moment the cart goes cold.**

> **Meta description (148 chars):** Reserve PrestaShop stock while a product sits in the cart. The last item stays yours until checkout, and returns to sale when the cart expires. MIT module.

---

## Why a cart without reservations oversells

PrestaShop takes stock off the shelf when an order is placed, not when a product goes into a cart.
On a shop where stock is genuinely limited — a wholesale shop, a shop selling machines, anything
with one piece in the warehouse — that gap is where the trouble is:

- **Two customers, one item** — both add it, both pay, one gets an apologetic phone call
- **The slow checkout loses** — a buyer filling in company details loses the item to a faster click
- **Angry cancellations** — an order cancelled for lack of stock costs more than the sale
- **No cron to set up** — reservations expire on their own, on ordinary shop traffic

## What the module does

Putting a product in the cart takes its quantity out of the available stock for everyone else. The
reservation is refreshed on every change to the cart and expires a set number of minutes after the
last one, at which point the stock goes back on sale. Placing the order releases the reservation,
because the order itself removes the stock.

### Key features

- **Every line in every cart** — products and combinations alike
- **Timer restarts on activity** — a customer still shopping does not lose their reservation
- **No cron** — expired reservations are cleared on ordinary front office traffic
- **The customer's own cart is not counted twice** — they see what is really left for them
- **One switch and one number** — enabled, and how many minutes

### How it looks from both sides

With five items in stock and a customer holding three, that customer can still add two more, and
everyone else sees two. Once the reservation expires or the cart is emptied, all five are back on
sale.

## Compatibility

| | |
|---|---|
| PrestaShop | 1.7.6 – 9.x |
| PHP | 7.4+ |
| Requirements | none |
| Multistore | Reservations are recorded per shop |
| Themes | Works with any theme — the module changes stock, not templates |

The module performs no core overrides; it uses the `actionOverrideQuantityAvailableByProduct` hook
that PrestaShop provides for exactly this. It creates one table, `m4p_reservation`, releases every
reservation and drops the table on uninstall.

## Installation

1. Upload and install the module from **Modules → Module Manager**.
2. Open the module configuration and set how long stock stays reserved. The default is 15 minutes.
3. Add a product with limited stock to a cart and check the quantity available in another browser.

## Configuration options

| Setting | Description |
|---|---|
| **Reserve stock held in carts** | Turns reservations on. Off means PrestaShop behaves as usual. |
| **How long stock stays reserved** | Minutes counted from the last change to the cart. |

## Frequently asked questions

**What happens when the reservation expires while the customer is still on the checkout page?**
The stock goes back on sale, and the order fails at validation if someone else took it meanwhile.
Set the time to cover a realistic checkout — 15 minutes is the default for a reason.

**Does a guest cart reserve stock too?**
Yes. Any cart with products reserves them; the module does not care whether the visitor is signed in.

**How are expired reservations cleaned up without a cron?**
Every front office page checks for reservations that are past their time and releases them. A shop
with no traffic has nothing to release for.

**Does it work with combinations?**
Yes, a reservation is recorded per product and combination, the same way the cart records lines.

**What happens to reserved stock when I uninstall the module?**
Every reservation is released before the table is dropped, so no stock stays held.

---

**Keywords:** PrestaShop stock reservation, cart reservation, prevent overselling, limited stock,
B2B inventory, hold stock in cart.

## License

MIT — see [LICENSE](LICENSE). Free to use commercially, fork and modify; keep the copyright notice.

## Contributing

Bug reports and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). For security
issues, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

---

Built by [Nice Code](https://nice-code.com/pl/produkty/sklep-b2b-prestashop) — we build B2B stores on PrestaShop.

© Nice Code sp. z o.o. (Modules4Presta) — released under the MIT license.
