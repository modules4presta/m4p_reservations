# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-11-26

### Added

- A deleted cart gives its reserved stock back at once (`actionObjectCartDeleteAfter`), whether it is deleted in the
  front office, the back office or by a script through `Cart::delete()`.
- Reservations of a cart that no longer exists in the database are released with the expired ones on the next
  front office page view.

## [1.1.0] - 2026-11-24

### Added

- `Cart` override giving a cart line back the customer's own reservation, so its stock is reduced only by other
  customers' reservations.

## [1.0.0] - 2026-09-28

### Added

- Stock of everything in a cart is reserved for a configurable number of minutes, refreshed on every
  change to the cart and released when it expires, when the cart is emptied or when the order is placed.
- Compensation so a customer is not charged twice for their own reservation when the shop checks how
  much they may still add.
- English and Polish translations, MIT license and the standard documentation set.
