# Analyse: class-wc-shortcode-cart.php

## 1. Klassen-Übersicht

`WC_Shortcode_Cart` rendert den `[woocommerce_cart]`-Shortcode: Sie verarbeitet optional eine per POST übermittelte Versandadresse für den Versandrechner, stößt die Warenkorb-Neuberechnung und Item-Validierung an und lädt anschließend das passende Template (leerer Warenkorb oder Warenkorb-Inhalt).

- **Elternklasse:** keine.
- **Wichtigste Abhängigkeiten:** `WC()->shipping()`, `WC()->customer`, `WC()->cart`, `WC_Validation`, `wc_get_template()`, WordPress Nonce-Funktionen (`wp_verify_nonce`).

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `calculate_shipping()` | Übernimmt POST-Adressdaten, validiert Postleitzahl, setzt Rechnungs-/Lieferadresse des Kunden |
| `output( $atts )` | Shortcode-Einstiegspunkt: verarbeitet ggf. Versandberechnung, validiert Cart-Items, rendert Template |

## 3. Auffälligkeiten & Probleme

1. **Zeile 62**: `if ( ! empty( $e ) ) { wc_add_notice( $e->getMessage(), 'error' ); }` innerhalb des `catch ( Exception $e )`-Blocks. Ein gefangenes Exception-Objekt ist nie "leer" (`catch` liefert immer eine Instanz, niemals `null`) – die Prüfung ist toter Code, der niemals `false` auswertet und nur unnötige kognitive Last erzeugt.
2. **Zeile 23 / Aufrufkette**: `calculate_shipping()` ist `public static` und führt eine zustandsändernde Operation aus (`WC()->customer->set_shipping_location()`, `->save()`), besitzt aber selbst **keine** Nonce-Prüfung. Der CSRF-Schutz liegt ausschließlich beim einzigen aktuellen Aufrufer `output()` (Zeile 85). Da die Methode public und static ist, könnte sie von anderem Code (Custom-Template, REST-Endpoint, Drittanbieter-Plugin) direkt aufgerufen werden und würde dann ungeschützt POST-Daten in die Kundenadresse übernehmen.
3. **Zeile 89 und 96** (`output()`): `WC()->cart->calculate_totals()` wird im Versand-Update-Zweig (Zeile 89) und danach unbedingt erneut (Zeile 96) aufgerufen. Bei einem Versand-Update-Request wird die vollständige (potenziell teure) Cart-Berechnung im selben Request doppelt ausgeführt.
4. **Zeile 84** (Kommentar `@todo remove in 4.0`): Zwei parallele Nonce-Actions (`woocommerce-shipping-calculator` und das alte `woocommerce-cart`) werden seit Jahren aus Kompatibilitätsgründen unterstützt; das referenzierte Aufräum-Ziel ("4.0") wurde nie umgesetzt – langjährige, nie eingelöste Tech-Debt-Notiz.

## 4. Verbesserungsvorschläge

**Mittel**
- Nonce-Prüfung in `calculate_shipping()` selbst verankern (oder die Methode explizit als "unsafe, caller must verify nonce" dokumentieren und `protected`/intern kennzeichnen), damit die Sicherheitsprüfung nicht ausschließlich vom aufrufenden Kontext abhängt.
- Doppelten `calculate_totals()`-Aufruf (Zeile 89 und 96) vermeiden, z.B. durch einen Flag, der signalisiert, dass die Berechnung im Versand-Zweig bereits erfolgt ist.

**Niedrig**
- Toten Leerheits-Check (Zeile 62) entfernen.
- Alten `woocommerce-cart`-Nonce-Fallback (Zeile 85) entfernen oder Entscheidung dokumentieren, warum er weiterhin nötig ist.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 7/10 |
| Testbarkeit | 6/10 |
| Wartbarkeit | 7/10 |
