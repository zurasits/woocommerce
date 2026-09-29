# Gesamtanalyse: Domäne Warenkorb

Dieses Dokument bündelt die Domänen-Karte (Ebene 1), die wichtigsten Abläufe (Ebene 2) und den Hook-Index (Ebene 3) für den WooCommerce-Warenkorb in einer zentralen, praxisorientierten Übersicht.

Alle Pfade sind relativ zu `plugins/woocommerce/`.

---

## 1. Domänen-Definition & Abgrenzung

**Kern der Domäne:** 
Verwaltung des Warenkorbinhalts (Artikel, Mengen), Berechnung der Summen (Subtotal, Steuer, Versand, Rabatt, Gesamt) und Persistierung dieses Zustands über die Session.

**Gehört bewusst NICHT dazu (Abgrenzung):**
*   **Coupon-Rabattberechnung** (`WC_Discounts`): Der Warenkorb speichert nur den Code; die eigentliche Rabatt-Mathematik ist eine eigene Domäne.
*   **Versandzonen/-methoden** (`WC_Shipping`): Der Warenkorb baut nur Pakete, die Ratenermittlung liegt außerhalb.
*   **Checkout-Prozess** (`WC_Checkout`): Nutzt den fertigen Warenkorb nur als Eingabe für die Bestellerstellung.
*   **Steuerlogik** (`WC_Tax`): Wird von den Cart-Totals aufgerufen, rechnet aber autark.

## 2. Use Cases (Fachliche Vorgänge)
1. Produkt in den Warenkorb legen
2. Menge ändern / Produkt entfernen
3. Totals berechnen (Summen, Steuern, Versand)
4. Warenkorb aus Session laden und speichern
5. Warenkorb anzeigen (Cart-Seite, Mini-Cart, Block)

---

## 3. Architektur & Dateien nach Schicht

 WooCommerce fährt zweigleisig: Es gibt den **Klassischen Pfad** (PHP/Templates) und den **Block-Pfad** (React/Store API). Beide nutzen intern dieselbe Domänenlogik, haben aber unterschiedliche Eintrittspunkte.

| Schicht | Dateien (Auszug der Wichtigsten) | Rolle / Use Case |
|---|---|---|
| **Eintritt (Klassisch)** | `includes/class-wc-ajax.php` | AJAX-Endpoints (z.B. `add_to_cart`, `get_cart_totals`) |
| **Eintritt (Klassisch)** | `includes/class-wc-form-handler.php` | POST-Requests (Formulare ohne AJAX) |
| **Eintritt (Block/API)** | `src/StoreApi/Routes/V1/Cart*.php` | REST API (z.B. `/wc/store/v1/cart/add-item`) |
| **Domänenlogik** | `includes/class-wc-cart.php` | Zentrales Objekt (Inhalt, Totals-Zugriff, Validierung) |
| **Domänenlogik** | `includes/class-wc-cart-totals.php` | Berechnungs-Engine (wird pro Kalkulation neu instanziiert) |
| **Domänenlogik** | `includes/class-wc-cart-fees.php` | Registry für Zusatzgebühren |
| **Persistenz** | `includes/class-wc-cart-session.php` | Bindeglied zur DB-Session & Usermeta (Persistent Cart) |

---

## 4. Die wichtigsten Abläufe

### Ablauf A: Totals berechnen (Der Konvergenzpunkt)
Jede Änderung am Warenkorb endet hier. Dieser Pfad ist die häufigste Quelle für Performance-Probleme und Berechnungsfehler.

**Ablauf-Schritte (`WC_Cart::calculate_totals()`):**
1. **Reset:** Alle vorherigen Summen auf 0 setzen.
2. **Pre-Hook:** `woocommerce_before_calculate_totals` feuert. **(Der Ort für Preisänderungen!)**
3. **Engine Start:** `new WC_Cart_Totals( $this )` wird gestartet.
4. **Teilberechnungen (in `WC_Cart_Totals::calculate()`):**
   *   *Artikel & Rabatte:* `calculate_item_totals()`
   *   *Versand:* `calculate_shipping_totals()` (Zyklischer Aufruf zurück zu `WC_Cart::calculate_shipping()`)
   *   *Gebühren:* `calculate_fee_totals()`
   *   *Gesamtsumme:* `calculate_totals()` addiert alles zusammen.
5. **Post-Hook:** `woocommerce_after_calculate_totals` feuert (nur fürs Logging, keine Preisänderungen mehr!).

### Ablauf B: Produkt in den Warenkorb legen (Add to Cart)
1. **Eintritt:** `WC_AJAX::add_to_cart()` oder `CartAddItem.php` (Store API) empfängt Produkt-ID und Menge.
2. **Validierung:** `WC_Cart::add_to_cart()` prüft Kaufbarkeit (`is_purchasable()`, Lagerbestand). 
   *   *Hook:* `woocommerce_add_to_cart_validation` (Fehler werfen, wenn Kauf blockiert werden soll).
3. **Item-Erstellung:** Das Item-Array wird generiert (inkl. Variationen und Custom Data).
   *   *Hook:* `woocommerce_add_cart_item_data` (Custom-Daten anhängen).
4. **Zusammenführen:** Gibt es das Produkt (mit identischem Daten-Hash) schon im Cart, wird die Menge erhöht. Sonst wird es als neue Zeile hinzugefügt.
5. **Speichern:** `WC_Cart_Session::set_session()` aktualisiert die Datenbank.
6. **Totals-Update:** `calculate_totals()` wird aufgerufen, um die neuen Summen zu bilden.

### Ablauf C: Persistenz (Session vs. Persistent Cart)
WooCommerce speichert den Warenkorb an zwei Orten:
*   **Session (Gäste & Login):** In der Tabelle `wp_woocommerce_sessions`. Identifiziert über einen Cookie (`wp_woocommerce_session_*`).
*   **Persistent Cart (Nur eingeloggte User):** In der Tabelle `wp_usermeta` unter dem Key `_woocommerce_persistent_cart_1`. Dadurch behält der Kunde seinen Warenkorb, wenn er sich am PC abmeldet und am Smartphone anmeldet.

---

## 5. Hook-Index & Eingriffs-Rezepte

### Symptom-Index (Erste Hilfe)
| Symptom / Problem | Wahrscheinliche Ursache |
|---|---|
| Rabattpreis wird im Warenkorb ignoriert | Falscher Hook genutzt (`woocommerce_get_price_html` ändert nur Optik). Nutze Rezept 1. |
| Endlosschleife / Timeout im Warenkorb | `$cart->calculate_totals()` wurde fälschlicherweise in einem Berechnungs-Hook aufgerufen. |
| Zusatzgebühr hat keine Steuer | Gebühr wurde nicht über `WC_Cart_Fees` bzw. Rezept 2 hinzugefügt. |

### Rezept 1: Artikelpreis dynamisch ändern (z.B. Mengenrabatt)
**Wichtig:** Preise immer *vor* der eigentlichen Berechnung anpassen! Niemals `$cart->calculate_totals()` innerhalb der Funktion aufrufen.
```php
add_action( 'woocommerce_before_calculate_totals', 'custom_dynamic_price', 10, 1 );
function custom_dynamic_price( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    foreach ( $cart->get_cart() as $cart_item ) {
        // Beispiel: Preis auf 10 EUR setzen
        $cart_item['data']->set_price( 10.00 );
    }
}
```

### Rezept 2: Zusatzgebühr hinzufügen
**Wichtig:** Hierfür gibt es einen dedizierten Hook.
```php
add_action( 'woocommerce_cart_calculate_fees', 'custom_handling_fee', 10, 1 );
function custom_handling_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    // Parameter: Name, Betrag, Steuerpflichtig?, Steuerklasse
    $cart->add_fee( 'Bearbeitungsgebühr', 5.00, true, '' );
}
```

### Rezept 3: Eigene Daten an ein Cart-Item hängen
**Wichtig:** Daten, die hier angehängt werden, bleiben über die Session erhalten und werden später an die Bestellung (Order Item Meta) übergeben, sofern man sie dort ebenfalls einhakt.
```php
add_filter( 'woocommerce_add_cart_item_data', 'custom_add_item_data', 10, 3 );
function custom_add_item_data( $cart_item_data, $product_id, $variation_id ) {
    if ( isset( $_POST['custom_field'] ) ) {
        $cart_item_data['my_custom_data'] = sanitize_text_field( $_POST['custom_field'] );
    }
    return $cart_item_data;
}
```
