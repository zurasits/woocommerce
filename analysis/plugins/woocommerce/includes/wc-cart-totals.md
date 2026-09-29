# Analyse: class-wc-cart-totals.php

## 1. Klassen-Übersicht

`WC_Cart_Totals` berechnet für ein übergebenes `WC_Cart`-Objekt sämtliche Summen (Item-Subtotale, Rabatte, Versand, Gebühren, Steuern, Gesamtsumme) in Cent-Präzision und schreibt die Ergebnisse über Setter direkt in das `WC_Cart`-Objekt zurück. Sie ist laut Klassenkommentar (Zeile 4-6) bewusst als "internes API" konzipiert: `final`, alle Methoden bis auf zwei `protected`.

- **Elternklasse:** keine; nutzt Trait `WC_Item_Totals` (geteilte Rundungslogik mit `WC_Abstract_Order`).
- **Wichtigste Abhängigkeiten:** `WC_Cart` (wird im Konstruktor injiziert und im Zuge der Berechnung mutiert), `WC_Discounts` (Rabattlogik), `WC_Coupon`, `WC_Tax`, `Automattic\WooCommerce\Utilities\NumberUtil`, `ProductTaxStatus`-Enum, diverse `wc_*`-Helper (`wc_add_number_precision_deep`, `wc_remove_number_precision_deep`, `wc_prices_include_tax`, `wc_tax_enabled`), WordPress-Hooks (`apply_filters`/`do_action`).

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `__construct( &$cart )` | Nimmt `WC_Cart` entgegen, prüft VAT-Exempt-Status des Kunden und stößt sofort die vollständige Berechnung (`calculate()`) an |
| `get_total( $key, $in_cents )` | Einzelnen Summenwert abfragen (z.B. `items_total`, `shipping_total`) |
| `get_totals( $in_cents )` | Alle berechneten Summen als Array abfragen |

Alle übrigen ~30 Methoden sind `protected` (interne Normalisierungs-, Berechnungs- und Rundungslogik) – konsistent mit der im Klassenkopf dokumentierten Design-Entscheidung.

## 3. Auffälligkeiten & Probleme

1. **Zeile 138** (`__construct`): Der Konstruktor ruft `$this->calculate()` auf und löst damit als Nebeneffekt der reinen Objekterzeugung eine vollständige Neuberechnung samt Mutation des übergebenen `WC_Cart`-Objekts aus. Ein Objekt lässt sich nicht ohne sofortige Vollberechnung instanziieren – erschwert Unit-Tests und macht Konstruktion "teuer" und unvorhersehbar.
2. **Zeile 507-525** (`get_item_costs_by_tax_class()`): Toter Code – die Methode wird innerhalb der Klasse und im restlichen Repository nirgends aufgerufen (verifiziert per Suche). Sie überschneidet sich inhaltlich stark mit `get_tax_class_costs()` (Zeile 246-266), die tatsächlich verwendet wird, aber leicht abweichende Semantik hat (kein `array_filter`, keine Sonderbehandlung negativer Beträge). Zwei ähnliche Methoden für denselben Zweck erhöhen die Verwechslungsgefahr.
3. **Zeile 368-382** (`get_coupons_from_cart()`): Es wird dynamisch eine Property `$coupon->sort` auf `WC_Coupon`-Objekten gesetzt, die in dieser Klasse nicht deklariert ist. Dynamische Properties sind seit PHP 8.2 deprecated (sofern `WC_Coupon`/`WC_Data` nicht explizit `#[AllowDynamicProperties]` nutzt) und koppeln `WC_Cart_Totals` eng an die interne Struktur eines fremden Domänenobjekts.
4. **Zeile 250**: `array_fill_keys( $item_tax_classes + $shipping_tax_classes + $fee_tax_classes, 0 )` nutzt den Array-Union-Operator `+` zur "Verkettung" dreier Listen. Da `wp_list_pluck()` numerisch indizierte Arrays liefert, funktioniert dies nur, weil kollidierende Integer-Keys stillschweigend die ersten Werte behalten (nicht alle Klassen werden zwingend aufgenommen, falls dies je zu Listen unterschiedlicher Länge mit divergierenden Werten führt) – funktional zufällig korrekt, aber nicht offensichtlich und leicht mit `array_merge()` zu verwechseln.
5. **Zeile 688-690, 752-754, 823-828, 844-847, 860-862** (`calculate_item_totals()`, `calculate_item_subtotals()`, `calculate_discounts()`, `calculate_fee_totals()`, `calculate_shipping_totals()`): Jede Berechnungsmethode schreibt parallel zur eigentlichen Summenbildung direkt in `$this->cart->cart_contents[...]` bzw. ruft diverse `$this->cart->set_*()`-Setter auf. Berechnung und Mutation des Cart-Zustands sind untrennbar vermischt – die reine Rechenlogik lässt sich nicht isoliert testen, ohne ein vollständiges `WC_Cart`-Objekt zu simulieren.
6. **Zeile 871 vs. 874** (`calculate_totals()`): `total` wird aus Cent-präzisen Werten auf 0 Dezimalstellen gerundet, während `shipping_and_fee_taxes` aus bereits auf Dezimalstellen reduzierten Werten (`get_merged_taxes( false, ... )`) erneut auf `wc_get_price_decimals()` gerundet wird. Zwei unterschiedliche Präzisions-Domänen (Cent vs. Dezimal) werden in derselben Methode gemischt verarbeitet – erhöht das Risiko von Rundungsfehlern bei künftigen Änderungen.
7. **Zeile 289-296** (`get_fees_from_cart()`): `$max_discount` wird bei jeder negativen Gebühr innerhalb der Schleife neu aus `items_total`, `shipping_total` und dem bisherigen `$fee_running_total` berechnet. Bei mehreren negativen Gebühren in Folge ist die Deckelungslogik schwer nachzuvollziehen (kumulativer Effekt mehrerer Kappungen), zusätzlich unnötiger wiederholter Berechnungsaufwand.

## 4. Verbesserungsvorschläge

**Hoch**
- Konstruktor entkoppeln: `calculate()` nicht implizit in `__construct()` aufrufen, sondern lazy bei erstem `get_total()`/`get_totals()`-Zugriff oder über einen expliziten Aufruf durch den Aufrufer ausführen. Verbessert Testbarkeit und macht Seiteneffekte sichtbar.
- Tote Methode `get_item_costs_by_tax_class()` (Zeile 507-525) entfernen oder mit `get_tax_class_costs()` (Zeile 246) konsolidieren, um Verwirrung durch zwei fast identische Methoden zu vermeiden.

**Mittel**
- Dynamische `sort`-Property auf `WC_Coupon` (Zeile 368-382) vermeiden – stattdessen lokale Zuordnung `coupon_id => sort_value` (z.B. `SplObjectStorage` oder Array) für die Sortierung verwenden.
- `array_fill_keys( ... + ... + ... )` (Zeile 250) durch `array_merge()` mit `array_unique()` ersetzen oder per Kommentar begründen, warum die Union-Semantik hier gewünscht ist.

**Niedrig**
- `$max_discount`-Berechnung in `get_fees_from_cart()` (Zeile 289) aus der Schleife herausziehen bzw. Zwischenergebnis cachen, um wiederholte Berechnung bei mehreren negativen Gebühren zu vermeiden.
- Rundungs-Konventionen (Cent- vs. Dezimal-Präzision) in `calculate_totals()` (Zeile 871, 874) dokumentieren oder vereinheitlichen, um künftige Off-by-One-Cent-Fehler zu vermeiden.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 7/10 |
| Testbarkeit | 4/10 |
| Wartbarkeit | 6/10 |
