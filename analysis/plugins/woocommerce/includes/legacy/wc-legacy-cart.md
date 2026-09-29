# Analyse: class-wc-legacy-cart.php

## 1. Klassen-Übersicht

`WC_Legacy_Cart` ist eine abstrakte Basisklasse, die ausschließlich der Abwärtskompatibilität dient: Über Magic Methods (`__isset`, `__get`, `__set`) werden alte, direkte Property-Zugriffe (z.B. `$cart->subtotal`, `$cart->fees`) auf die aktuellen Getter/Setter von `WC_Cart` umgeleitet. Zusätzlich enthält sie eine Reihe deprecated öffentlicher Methoden, die entweder direkt an neue Methoden delegieren oder nur noch eine Deprecation-Warnung ausgeben.

- **Elternklasse:** keine; wird von `WC_Cart` erweitert (`WC_Cart extends WC_Legacy_Cart`).
- **Wichtigste Abhängigkeiten:** setzt voraus, dass die erbende Klasse (`WC_Cart`) sämtliche referenzierten Getter/Setter (`get_total()`, `set_subtotal()`, `fees_api()`, `session`, `cart_contents` usw.) bereitstellt; nutzt `wc_deprecated_function()`/`wc_deprecated_argument()`, `wc_get_price_decimals()`, `wc_prices_include_tax()`.

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `__isset()`, `__get()`, `__set()` | Magic-Property-Bridge zu aktuellen Getter/Setter-Methoden |
| `get_cart_from_session()`, `maybe_set_cart_cookies()`, `set_session()`, `get_cart_for_session()`, `persistent_cart_update()`, `persistent_cart_destroy()` | Reine Delegation an `$this->session` (Verschiebung nach `WC_Cart_Session` in 3.2.0) |
| `get_cart_discount_total()`, `get_cart_discount_tax_total()`, `add_discount()` | Umbenennungs-Wrapper um aktuelle Methoden |
| `get_discounted_price()`, `remove_taxes()`, `init()`, `apply_cart_discounts_after_tax()`, `apply_product_discounts_after_tax()`, `get_discounts_after_tax()`, `get_discounts_before_tax()`, `get_order_discount_total()` | Vollständig deprecated, größtenteils No-Op oder reine Warnausgabe |
| `get_cart_url()`, `get_checkout_url()`, `ship_to_billing_address_only()`, `coupons_enabled()` | Wrapper um globale `wc_*`-Funktionen |

## 3. Auffälligkeiten & Probleme

1. **Zeile 109** (`public function &__get( $name )`): Die Methode gibt grundsätzlich per Referenz zurück, obwohl die meisten `case`-Zweige (z.B. `cart_contents_total`, `total`, `subtotal` – Zeile 122-133) einen temporären Wert statt einer echten Referenz liefern. Nur wenige Fälle (`taxes`, `shipping_taxes`, `coupon_discount_amounts`, `coupon_discount_tax_amounts`, `fees` – Zeile 169-192) nutzen die Referenzsemantik tatsächlich (`$value = &$this->totals[...]`). Diese Vermischung ist verwirrend und fehleranfällig, falls künftig weitere Fälle hinzugefügt werden, die fälschlich Referenzverhalten erwarten.
2. **Zeile 181-192 und Zeile 262-265** (`__get`/`__set`, Fall `'fees'`): `__get( 'fees' )` synchronisiert bei jedem Lesezugriff `$this->fees` (dynamische Property) frisch aus `fees_api()->get_fees()` und gibt sie per Referenz zurück – ein Legacy-Muster wie `$cart->fees[] = $obj;` mutiert dadurch nur diese dynamische Property, **ohne** dass die Änderung je an `fees_api()` zurückgespielt wird. `__set( 'fees', $value )` überschreibt ebenfalls nur `$this->fees`, ruft aber `fees_api()->set_fees()` nicht auf. Legacy-Code, der Gebühren auf diese Weise manipuliert, hat dadurch schlicht keine Wirkung auf die tatsächliche Berechnung in `WC_Cart_Totals` – ein funktionaler Bug im Kompatibilitäts-Layer.
3. **Uneinheitliche Deprecation-Warnungen in `__get()`**: Manche Fälle rufen `wc_deprecated_function()`/`wc_deprecated_argument()` auf (`taxes`, `shipping_taxes`, `fees`, `tax`, `discount_total`, `tax_display_cart` – Zeile 168-206), andere (z.B. `cart_contents_total`, `total`, `subtotal`, `shipping_total` – Zeile 122-151) tun dies nicht, obwohl sie über dieselbe generische Magic-Methode für als "legacy" deklarierte Properties (siehe `$legacy_keys` in `__isset()`) laufen. Entwickler, die auf Altcode migrieren, erhalten also inkonsistente Hinweise, welche Zugriffe wirklich veraltet sind.
4. **Zeile 21** (`#[AllowDynamicProperties]`): Erlaubt beliebige dynamische Properties auf der gesamten Klassenhierarchie (inkl. `WC_Cart`). In Kombination mit dem `default`-Zweig in `__set()` (Zeile 266-268: `$this->$name = $value;`) führt ein Tippfehler in einem Property-Namen (z.B. `$cart->totla = 5;`) nicht zu einem Fehler, sondern erzeugt still eine neue, nutzlose dynamische Property – kann echte Bugs maskieren.
5. **Zeile 336-343** (`get_discounted_price()`, deprecated): Greift ungeprüft auf `$this->cart_contents[ $cart_item_key ]['line_total']` zu. Existiert der Cart-Item-Key nicht (mehr) im Warenkorb, führt dies zu einer PHP-Warnung ("Undefined array key") statt eines kontrollierten Fehlers.

## 4. Verbesserungsvorschläge

**Hoch**
- Synchronisationslücke bei `fees` (Zeile 181-192, 262-265) schließen: `__set( 'fees', $value )` sollte `fees_api()->set_fees( $value )` aufrufen, damit Legacy-Zugriffe tatsächlich Wirkung zeigen – oder, falls dies technisch nicht sauber nachrüstbar ist, zumindest eine deutliche Warnung ausgeben, dass Schreibzugriffe wirkungslos sind.

**Mittel**
- Deprecation-Warnungen in `__get()` einheitlich für alle in `__isset()` gelisteten Legacy-Properties ausgeben (nicht nur für eine Teilmenge), damit Entwickler zuverlässig erkennen, welche Zugriffe migriert werden müssen.
- Referenzrückgabe (`&__get`) auf die tatsächlich referenzbenötigenden Fälle beschränken bzw. dokumentieren, warum die Methode generell per Referenz deklariert ist.

**Niedrig**
- `get_discounted_price()` (Zeile 336-343) um eine `isset()`-Prüfung auf `$cart_item_key` ergänzen, auch wenn die Methode deprecated ist.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 5/10 |
| Testbarkeit | 4/10 |
| Wartbarkeit | 5/10 |
