# Analyse: class-wc-cart.php

## 1. Klassen-Übersicht

`WC_Cart` speichert den Warenkorbinhalt, angewendete Coupons, berechnete Summen (Steuern, Versand, Gebühren, Rabatte) und stellt die Kundensession-Bindung her. Sie ist die zentrale Fassade, über die Frontend (Shortcodes) und Store API auf Warenkorb-Operationen (Hinzufügen, Entfernen, Mengenänderung, Coupon-Handling, Summenberechnung) zugreifen.

- **Elternklasse:** `WC_Legacy_Cart` (stellt deprecated Properties via `__get`/`__set` auf aktuelle Getter/Setter um).
- **Komposition:** `WC_Cart_Session` (Session-Handling), `WC_Cart_Fees` (Fees API), `WC_Cart_Totals` (wird in `calculate_totals()` instanziiert, übernimmt eigentliche Berechnung).
- **Wichtigste Abhängigkeiten:** globale `WC()`-Instanz (`session`, `shipping()`, `countries`, `customer`), `WC_Coupon`, `WC_Tax`, `WC_Product`, diverse `Automattic\WooCommerce\Utilities\*`-Helper (DiscountsUtil, ShippingUtil, NumberUtil), WordPress-Hooks (`apply_filters`/`do_action`) an praktisch jeder Methode.

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `get_cart_contents()`, `get_cart()`, `get_cart_item()`, `is_empty()` | Zugriff auf Warenkorbinhalt |
| `set_cart_contents()`, `set_quantity()`, `add_to_cart()`, `remove_cart_item()`, `restore_cart_item()`, `empty_cart()` | Mutation des Warenkorbinhalts |
| `get_subtotal()`, `get_total()`, `get_cart_contents_total()`, `get_cart_tax()`, `get_taxes()`, `get_tax_totals()` u.a. | Getter für berechnete Summen/Steuern (Wrapper um `$totals`-Array) |
| `set_subtotal()`, `set_total()`, `set_shipping_total()` u.a. | Setter für `$totals`-Array |
| `calculate_totals()`, `calculate_shipping()`, `calculate_fees()` | Trigger für Neuberechnung (delegiert an `WC_Cart_Totals`, `WC_Cart_Fees`, Shipping-API) |
| `apply_coupon()`, `remove_coupon()`, `remove_coupons()`, `has_discount()`, `get_coupons()`, `get_coupon_discount_amount()` | Coupon-Verwaltung |
| `check_cart_items()`, `check_cart_item_validity()`, `check_cart_item_stock()`, `check_cart_item_sold_individually()`, `check_cart_coupons()`, `check_customer_coupons()` | Validierung vor Checkout |
| `needs_shipping()`, `show_shipping()`, `get_shipping_packages()`, `get_shipping_methods()` | Versand-Logik |
| `add_fee()`, `get_fees()`, `fees_api()` | Zusatzgebühren |
| `get_cart_hash()`, `generate_cart_id()`, `find_product_in_cart()` | Hilfsfunktionen für Item-Identität |

## 3. Auffälligkeiten & Probleme

1. **Zeile 1137–1430** (`add_to_cart()`): ~290 Zeilen lange Methode mit tief verschachtelter Validierungs-, Variations- und Bestandslogik in einem einzigen try/catch-Block. Schwer testbar, hohe kognitive Last bei Änderungen, Verstoß gegen Single-Responsibility.
2. **Zeile 1151**: `if ( $quantity <= 0 || ! $product_data || ... ) { return false; }` – im Gegensatz zu allen anderen Validierungsfehlern in derselben Methode (die per `throw new Exception(...)` eine Nutzermeldung erzeugen) wird hier still `false` zurückgegeben. Der Nutzer erhält keine Rückmeldung, warum das Hinzufügen fehlgeschlagen ist.
3. **Zeile 1424–1429**: Exceptions werden als regulärer Kontrollfluss für Validierungsfehler genutzt, während andere Prüfmethoden der Klasse (`check_cart_item_stock()`, `check_cart_item_validity()`) `WP_Error` zurückgeben. Zwei unterschiedliche Fehlerkonventionen in derselben Klasse erschweren die Wiederverwendung der Validierungslogik.
4. **Zeile 1882, 1888, 1890** (`get_cart_shipping_total()`): Zugriff auf `$this->shipping_total` / `$this->shipping_tax_total` über die deprecated Magic-Properties aus `WC_Legacy_Cart::__get()`, obwohl dieselbe Klasse eigene Getter `get_shipping_total()` / `get_shipping_tax()` bereitstellt (siehe Zeile 277, 287). Unnötige Indirektion und inkonsistenter Stil.
5. **Zeile 651–657** (`get_cart()`): Getter mit Seiteneffekt – lädt bei Bedarf den Warenkorb aus der Session (`$this->session->get_cart_from_session()`). Verstoß gegen Command-Query-Separation; für Aufrufer überraschend, dass ein simpler Getter I/O auslösen kann.
6. **Zeile 2053**: `$this->applied_coupons += $coupons_to_keep;` nutzt den Array-Union-Operator statt `array_merge()`. Funktioniert nur korrekt, solange numerische Keys nicht kollidieren; fehleranfällig bei künftigen Refactorings.
7. **Durchgehend** (z.B. Zeile 710, 1524, 1597, 1817–1818, 2077, 2083, 2192): Direkte, harte Kopplung an globale `WC()`-Singletons (`WC()->session`, `WC()->shipping()`, `WC()->countries`, `WC()->customer`) statt Dependency Injection. Macht isolierte Unit-Tests ohne vollständigen WooCommerce/WordPress-Bootstrap praktisch unmöglich.
8. **Zeile 803, 1910, 2018, 2060, 2113** (`check_cart_coupons()`, `check_customer_coupons()`, `apply_coupon()`, `get_coupons()`): `new WC_Coupon( $code )` wird wiederholt pro Coupon-Code neu instanziiert, auch innerhalb von Schleifen über dieselben `applied_coupons`. Potenziell wiederholte DB-/Cache-Zugriffe (N+1-artig) bei mehreren Coupons.
9. **Gesamte Datei (2462 Zeilen)**: Die Klasse vereint Warenkorbinhalt, Summenberechnung, Coupon-Handling, Versand, Gebühren und Session-Bindung – klassisches "God Object". Erschwert gezielte Änderungen, da Nebenwirkungen über viele Verantwortungsbereiche hinweg möglich sind.

## 4. Verbesserungsvorschläge

**Hoch**
- `add_to_cart()` in kleinere, einzeln testbare private Methoden aufteilen (z.B. Variationsauflösung, Bestandsprüfung, Item-Merge), um Testbarkeit und Lesbarkeit zu verbessern.
- Zeile 1151: Bei ungültiger Menge/Produkt eine Fehlermeldung per `wc_add_notice()` ausgeben, analog zu den übrigen Validierungspfaden in derselben Methode.

**Mittel**
- Zugriffe auf globale `WC()`-Objekte hinter injizierbare Parameter/Interfaces legen (zumindest für `session`, `shipping()`, `customer`), um Unit-Tests ohne vollen WP-Bootstrap zu ermöglichen.
- Legacy-Magic-Property-Zugriffe (`$this->shipping_total`, `$this->shipping_tax_total`) durch die vorhandenen Getter ersetzen (Zeile 1882, 1888, 1890).
- Fehlerkonvention vereinheitlichen: entweder durchgängig `WP_Error` oder durchgängig Exceptions für Validierungslogik im Cart.

**Niedrig**
- `WC_Coupon`-Instanzen innerhalb eines Requests cachen (z.B. statisches Array `code => WC_Coupon`), um wiederholte Instanziierung zu vermeiden.
- Zeile 2053: `array_merge()` statt `+=` verwenden oder die Absicht der Key-Erhaltung dokumentieren.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 6/10 |
| Testbarkeit | 4/10 |
| Wartbarkeit | 5/10 |
