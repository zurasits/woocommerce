# Guardians for Online Shops

An autonomous protection layer that detects configuration, data and runtime
faults in an online shop before they cost revenue or block a purchase.

It grows domain by domain. The first module is **Cart Shield**, covering cart
and checkout: a product without a weight that silently kills shipping
calculation, a shipping zone with no method attached, a coupon that swallows
the shipping cost. Further domains — product data, upselling and others —
follow the same pattern, each with its own catalog of failure cases and its
own guardians.

The checks are not guesswork. Each domain is first analysed in the running
system, and every documented failure case becomes one small guardian that
watches for exactly that case.

## What it does

- Turns a catalog of known failure cases into individual, testable checks,
  each reporting one specific fault.
- Validates a **data set**, not an event. The same rule therefore works for a
  single edit, a bulk import (CSV/XML/JSON), an API call or a scheduled stock
  check.
- Keeps the rule core free of shop-system specifics. Each guardian separates
  the rule core (what counts as a fault, how severe) from the adapter (where
  the data comes from) and the trigger (when it runs). WooCommerce is the
  first implementation, not the target platform.
- Runs side-effect free, so any check can be executed as a dry run.

## Status

**Work in progress — early development, not usable yet.**

Only the cart domain is under way: it is analysed and 36 failure cases are
documented. The first guardian ("no shipping method available") exists as a
written contract only; no code has been implemented so far. Interfaces, scope
and structure still change. There is no release, and nothing here is ready for
a production shop.
