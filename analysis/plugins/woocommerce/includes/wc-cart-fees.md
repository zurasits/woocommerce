# Analyse: class-wc-cart-fees.php

## 1. Klassen-Übersicht

`WC_Cart_Fees` ist die "Fees API" des Warenkorbs: Sie verwaltet eine flache Liste beliebiger Zusatzgebühren (Objekte mit `id`, `name`, `tax_class`, `taxable`, `amount`, `total`), die üblicherweise über den Hook `woocommerce_cart_calculate_fees` von Drittanbietern hinzugefügt werden. Die eigentliche Steuerberechnung und Summierung der Gebühren erfolgt außerhalb dieser Klasse in `WC_Cart_Totals`.

- **Elternklasse:** keine (`final class`).
- **Wichtigste Abhängigkeiten:** `WC_Tax` (Validierung der Steuerklasse), WordPress-Funktionen (`wp_parse_args`, `sanitize_title`, `wc_string_to_bool`, `wc_format_decimal`), `WP_Error`.

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `__construct( $deprecated )` | Konstruktor; nimmt nur noch aus Kompatibilitätsgründen ein (deprecated) Argument entgegen |
| `init()` | Leerer Stub ohne Funktionalität |
| `add_fee( $args )` | Fügt eine einzelne Gebühr hinzu (validiert Steuerklasse/Betrag, generiert ID falls nötig) |
| `get_fees()` | Liefert alle Gebühren, sortiert nach Betrag |
| `set_fees( $raw_fees )` | Ersetzt alle Gebühren durch eine neue Liste (leert zuerst, ruft dann `add_fee()` je Eintrag) |
| `remove_all_fees()` | Leert die Gebührenliste (`set_fees()` ohne Argumente) |

## 3. Auffälligkeiten & Probleme

1. **Zeile 63** (`init()`): Die Methode ist ein leerer Stub ohne Implementierung und wird – im Gegensatz zu `WC_Cart_Session::init()`, das zahlreiche Hooks registriert – von `WC_Cart` nirgends aufgerufen (verifiziert per Suche in `class-wc-cart.php`). Totes bzw. vestigiales Interface-Relikt, vermutlich aus einer früheren Version, in der hier Hooks registriert wurden.
2. **Zeile 150-152** (`generate_id()`): Die automatisch generierte Fee-ID basiert ausschließlich auf `sanitize_title( $fee->name )`. Zwei Gebühren mit identischem oder ähnlich normalisiertem Namen (z.B. "Handling Fee" von zwei verschiedenen Plugins) erzeugen dieselbe ID; `add_fee()` weist die zweite dann still per `WP_Error` zurück (Zeile 83-85), was von aufrufendem Code oft nicht ausgewertet wird (z.B. wird der Rückgabewert in `WC_Cart::add_fee()` nicht geprüft) – ein Plugin-Autor bemerkt den Verlust seiner Gebühr unter Umständen nicht.
3. **Zeile 132-141** (`sort_fees_callback()`): Der Vergleichsoperator behandelt Gleichheit (`$a->amount === $b->amount`) fälschlich als "$b kommt zuerst" (`return 1`), gibt also nie `0` zurück. Für `uasort()` mit vielen betragsgleichen Gebühren ist das kein strenger Ordnungsvergleich; die Ausgabereihenfolge bei Gleichstand hängt vom internen Sortieralgorithmus ab statt von einer definierten Regel.
4. **Zeile 108-114** (`set_fees()`): Bei jedem Aufruf wird die komplette Gebührenliste geleert und über `add_fee()` neu aufgebaut. Dabei werden IDs, die beim ersten Hinzufügen ggf. generiert wurden, in `$raw_fees` als bereits gesetzt vorausgesetzt (sonst würden identische Namen erneut kollidieren) – impliziter Vertrag zwischen `get_fees()`-Rückgabeformat und `set_fees()`-Eingabeformat, der nicht dokumentiert ist.

## 4. Verbesserungsvorschläge

**Mittel**
- Rückgabewert von `add_fee()` in aufrufendem Code (`WC_Cart::add_fee()`, `set_fees()`) auf `WP_Error` prüfen und Entwicklerhinweis (`wc_doing_it_wrong` o.ä.) ausgeben, statt Gebühren still zu verwerfen.
- `generate_id()` um einen Kollisionsschutz erweitern (z.B. Suffix bei bereits vorhandener ID) statt stillschweigend abzulehnen.

**Niedrig**
- Toten `init()`-Stub entfernen oder – falls für eine zukünftige Hook-Registrierung vorgesehen – dokumentieren, warum er aktuell leer ist und nicht aufgerufen wird.
- `sort_fees_callback()` einen echten Dreiwege-Vergleich (`<=>`) verwenden, der bei Gleichheit `0` liefert.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 7/10 |
| Testbarkeit | 8/10 |
| Wartbarkeit | 7/10 |
