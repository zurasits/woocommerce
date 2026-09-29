# Analyse: wc-cart-functions.php

## 1. Datei-Übersicht

Keine Klasse, sondern eine prozedurale Sammlung von ~20 globalen Funktionen rund um den Warenkorb: Template-Helper für die Cart-/Checkout-Summenanzeige (`wc_cart_totals_*_html`), Warenkorb-Lifecycle-Funktionen (`wc_empty_cart()`, `wc_clear_cart_after_payment()`), Nachrichtentexte (`wc_add_to_cart_message()`), sowie Versandmethoden-Auswahl-Logik (`wc_get_default_shipping_method_for_package()` u.a.) und ein Hash-Utility für Cart-Item-Datenvalidierung.

- **Kein Klassenkontext** (prozedurale Datei, keine Elternklasse/Interfaces).
- **Wichtigste Abhängigkeiten:** globale `WC()`-Instanz (`cart`, `session`, `shipping()`, `customer`, `countries`), `WC_Coupon`, `WC_Order`, `wc_get_order()`, `wc_get_template()`, `Automattic\WooCommerce\Enums\{OrderStatus,ProductType}`, `Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils`, `Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils`.

## 2. Öffentliche Funktionen nach Kategorie

| Funktion | Zweck |
|---|---|
| `wc_protected_product_add_to_cart()`, `wc_empty_cart()`, `wc_clear_cart_after_payment()` | Cart-Lifecycle / Add-to-Cart-Validierung |
| `wc_add_to_cart_message()`, `wc_format_list_of_items()` | Erzeugung der "X wurde hinzugefügt"-Nutzermeldung |
| `wc_cart_totals_subtotal_html()`, `wc_cart_totals_shipping_html()`, `wc_cart_totals_taxes_total_html()`, `wc_cart_totals_coupon_label()`, `wc_cart_totals_coupon_html()`, `wc_cart_totals_order_total_html()`, `wc_cart_totals_fee_html()`, `wc_cart_totals_shipping_method_label()` | Template-Ausgabe-Helper für Cart-/Checkout-Summentabelle |
| `wc_get_chosen_shipping_method_ids()`, `wc_get_chosen_shipping_method_for_package()`, `wc_get_default_shipping_method_for_package()`, `wc_shipping_methods_have_changed()` | Auswahl-/Persistenzlogik für Versandmethoden je Paket |
| `wc_get_raw_referer()`, `wc_cart_round_discount()`, `wc_get_cart_item_data_hash()` | Sonstige Utility-Funktionen |

## 3. Auffälligkeiten & Probleme

1. **Zeile 121 + 156-170** (`wc_add_to_cart_message()` / `wc_format_list_of_items()`): `array_filter( $titles )` (Zeile 121) entfernt eventuell leere Einträge, **ohne die Keys neu zu indizieren** (Standardverhalten von `array_filter`). `wc_format_list_of_items()` bestimmt Position von "und"/Komma jedoch positionsbasiert über `$key + 1`/`$key + 2` (Zeile 162, 164) und setzt lückenlose, bei 0 beginnende Keys voraus. Enthält `$titles` eine Lücke (z.B. weil ein Titel leer war), erzeugt die Funktion eine falsch platzierte "und"-Verknüpfung und/oder ein überzähliges Komma. Aktuell praktisch nicht beobachtbar, da der Titel-String immer die HTML-Entities `&ldquo;…&rdquo;` enthält und daher nie tatsächlich leer ist – die Falle bleibt aber im Code latent bestehen für künftige Änderungen.
2. **Zeile 231, 276, 296, 360, 371 vs. Zeile 323**: Mehrere strukturell fast identische Template-Helper (`wc_cart_totals_*_html()`) geben gefilterte HTML-Strings direkt per `echo` mit `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` aus, ohne den durch `apply_filters()` durchgelaufenen Wert erneut zu bereinigen. Nur `wc_cart_totals_coupon_html()` (Zeile 323) wickelt das Ergebnis zusätzlich in `wp_kses()`. Uneinheitliche Verteidigungstiefe gegen XSS über per Filter eingeschleusten Drittanbieter-Code bei sonst gleichartigen Funktionen.
3. **Zeile 501 / `class-wc-cart.php:1812`**: Der Vergleich `'shortcode' === WC()->cart->cart_context` verwendet einen rohen String-Literal, der identisch (aber unabhängig) auch in `WC_Cart::get_cart_hash()`-Umfeld (`class-wc-cart.php`, Zeile 1812) vorkommt. Es existiert keine gemeinsame Konstante/Enum für die möglichen `cart_context`-Werte (`shortcode` vs. Block-Kontext) – ein Tippfehler an einer der beiden Stellen würde still zu falschem Verhalten führen, ohne dass ein Linter dies erkennen könnte.
4. **Zeile 312** (`wc_cart_totals_coupon_html()`): Zugriff auf `WC()->cart->display_cart_ex_tax`, eine deprecated Magic-Property aus `WC_Legacy_Cart::__get()`, obwohl `WC_Cart` mit `display_prices_including_tax()` einen aktuellen, nicht-deprecated Getter für denselben Sachverhalt bereitstellt (an anderer Stelle in derselben Datei, z.B. Zeile 333, 369, 386, korrekt verwendet).
5. **Gesamte Datei**: Vermischt vier lose zusammenhängende Verantwortungsbereiche (Cart-Lifecycle, Nutzer-Benachrichtigungen, Template-Rendering-Helper, Versandmethoden-Auswahl) in einer einzigen prozeduralen Datei ohne erkennbare thematische Untergliederung. Erschwert das gezielte Auffinden verwandter Funktionen.

## 4. Verbesserungsvorschläge

**Mittel**
- `wc_format_list_of_items()` robuster gestalten: `array_values()` auf die gefilterte Liste anwenden, bevor positionsbasiert iteriert wird, oder komplett auf einen index-unabhängigen Ansatz (z.B. `array_pop()` + `implode( ', ', ... )`) umstellen.
- Konsistente Escaping-Strategie für alle `wc_cart_totals_*_html()`-Funktionen festlegen (entweder durchgängig `wp_kses()` auf das gefilterte Ergebnis anwenden oder dokumentieren, warum bestimmte Fälle als sicher gelten).
- Für `cart_context` (Zeile 501, `class-wc-cart.php:1812`) eine Konstante oder ein Enum (`CartContext::SHORTCODE` analog zu `OrderStatus`/`ProductType`) einführen statt des wiederholten String-Literals.

**Niedrig**
- Zeile 312: `display_cart_ex_tax` durch `display_prices_including_tax()`-Negation ersetzen, analog zu den übrigen Stellen in derselben Datei.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 7/10 |
| Testbarkeit | 5/10 |
| Wartbarkeit | 6/10 |
