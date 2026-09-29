# Ablauf-Dokument: Warenkorb - Versandberechnung

| Feld | Wert |
|---|---|
| Domäne | Warenkorb |
| Use Case | Versandkosten im Warenkorb ermitteln und anzeigen |
| Stufe | 2 (Ablauf nach VORGEHEN.md) |
| Pfad | Klassisch (Shortcode, Templates, AJAX, `WC_Cart`) |

Dieses Dokument klammert den Block- und Store-API-Pfad aus, er wird hier nur dort erwähnt, wo er dieselbe Kernfunktion mitbenutzt.

---

## 1. Auslöser

Der Ablauf wird gestartet, wenn die Warenkorbsummen neu berechnet werden (`WC_Cart::calculate_totals()`), welche über `WC_Cart_Totals::get_shipping_from_cart()` in den Warenkorb zurückruft, um `WC_Cart::calculate_shipping()` aufzurufen.
Von außen passiert dies bei:
- Aufruf der Warenkorb-Seite (initiale Berechnung).
- Hinzufügen/Entfernen/Ändern von Positionen im Warenkorb.
- Absenden des Versandrechners (`class-wc-shortcode-cart.php`, AJAX über `cart.js`).

---

## 2. Ablauf

Die Aufrufkette der eigentlichen Versandberechnung:

| # | Datei | Methode | Zeile | Was passiert |
|---|---|---|---|---|
| 1 | `class-wc-cart.php` | `calculate_shipping()` | 1584 | Setzt Versandsummen auf 0, `has_calculated_shipping = false`. |
| 2 | `class-wc-cart.php` | `show_shipping()` / `needs_shipping()` | 1592 | Bricht ab, falls Versand deaktiviert, nicht konfiguriert oder Adresse unvollständig ist. |
| 3 | `class-wc-cart.php` | `get_shipping_packages()` | 1661 | Baut ein Paket aus allen versandpflichtigen Positionen. Nutzt `WC_Customer` für die Zieladresse. |
| 4 | `class-wc-shipping.php` | `calculate_shipping()` | 255 | Iteriert über alle gebauten Pakete, führt Filter `woocommerce_shipping_packages` aus. |
| 5 | `class-wc-shipping.php` | `calculate_shipping_for_package()`| 311 | Prüft Paket (`is_package_shippable()`), liest aus dem Session-Cache (`shipping_for_package_<hash>`). |
| 6 | `class-wc-shipping.php` | `load_shipping_methods()` | 339 | Ermittelt Zone und lädt Methoden (bei Cache-Miss). |
| 7 | `class-wc-shipping-zones.php`| `get_zone_matching_package()` | 159 | Sucht Zone per Objektcache (`shipping_zones`) über md5(Adresse). |
| 8 | `class-wc-shipping-zone-data-store.php`| `get_zone_id_from_package()` | 305 | Rohes SQL-Matching gegen Zone anhand der Adresse (`LIMIT 1`). Fallback auf Zone 0 (Rest der Welt). |
| 9 | `abstract-wc-shipping-method.php`| `get_rates_for_package()` | 256 | Iteriert Methoden, prüft `is_available()` und delegiert an `calculate_shipping()` der konkreten Methode. |
| 10 | `abstract-wc-shipping-method.php`| `add_rate()` | 291 | Erzeugt `WC_Shipping_Rate`, berechnet Versandsteuer via `WC_Tax::get_shipping_tax_rates()`. |
| 11 | `class-wc-shipping.php` | (anonym/inline) | 370 | Blendet optional alle Raten außer Gratisversand aus (`woocommerce_shipping_hide_rates_when_free`). |
| 12 | `class-wc-shipping.php` | (anonym/inline) | 398 | Ausführung Filter `woocommerce_package_rates`. |
| 13 | `class-wc-shipping.php` | (anonym/inline) | 407 | Schreibt das berechnete Raten-Array in die Session. |
| 14 | `ShippingUtil.php` | `select_shipping_rate()` | 20 | Reduziert die Liste auf die je Paket gewählte Methode. |
| 15 | `wc-cart-functions.php` | `wc_get_chosen_shipping_method_for_package()` | 446 | Setzt/Korrektur der gewählten Rate, wenn alte Auswahl veraltet oder Ratenanzahl geändert. |
| 16 | `wc-cart-functions.php` | `wc_get_default_shipping_method_for_package()` | 497 | Fallback-Vorauswahl (erste Rate, bzw. Gratisversand bei Gutschein). |
| 17 | `class-wc-cart.php` | `calculate_shipping()` | 1599 | Übernimmt Kosten und Steuern der gewählten Raten in die Cart-Totals. |
| 18 | `class-wc-cart-totals.php`| `calculate()` | 855 | Bucht `shipping_total` und `shipping_tax` in die Gesamtsummen des Warenkorbs ein. |

---

## 3. Ein-/Ausgaben

**Eingaben:**
- **Warenkorb-Inhalt:** Produkte, Mengen, Preise (beeinflussen Gratisversand, Paketaufteilung).
- **Kunde/Adresse:** `WC_Customer`, liefert Land, Bundesland, PLZ zur Zonenfindung.
- **Konfiguration:** `wp_options` (generelle Schalter), Zonen, Versandmethoden, Steuersätze.
- **Session-Cache:** Vorher berechnete Raten und alte Auswahl.

**Ausgaben:**
- **Ratenliste (`packages`):** Arrays von `WC_Shipping_Rate`-Objekten pro Paket.
- **Summen:** Versandkosten netto, Versandsteuer(n).
- **Session-Updates:** Cache und die ID der gewählten Methode.

---

## 4. Zustandsänderungen

| Ort | Schlüssel / Zustand | Persistenz |
|---|---|---|
| Session | `shipping_for_package_<hash>` | Cache der Raten je Paket-Hash, invalidiert bei Adress- oder Inhaltsänderung. |
| Session | `chosen_shipping_methods` | Die gewählte Rate je Paket-Index. |
| Session | `shipping_method_counts` | Anzahl gefundener Raten (als Basis für `wc_shipping_methods_have_changed()`). |
| Cache | `shipping_zones` (Group) | Objektcache der Zonenauflösung, getrieben durch Transient `shipping`. |
| `WC_Cart` | `shipping_total`, `shipping_tax` | Laufzeit-Zustand für Totals-Ausgabe. |

---

## 5. Hooks im Ablauf

- **`woocommerce_cart_shipping_packages`** (Filter, Schritt 3): Paketierung manipulierbar.
- **`woocommerce_shipping_packages`** (Filter, Schritt 4): Pakete inkl. berechneter Raten vor Session-Speicherung.
- **`woocommerce_get_zone_criteria`** (Filter, Schritt 8): SQL für Zonen-Erkennung anpassbar.
- **`woocommerce_package_rates`** (Filter, Schritt 12): Kern-Hook für Preis-/Ratenmanipulation (Drittanbieter klinken sich hier oft ein).
- **`woocommerce_shipping_chosen_method`** (Filter, Schritt 16): Erlaubt Überschreiben der Default-Vorauswahl.

---

## 6. Fehlerpfade

- **`needs_shipping()` ist false:** (z. B. nur virtuelle Produkte im Warenkorb). Schritte 3-18 fallen aus, es werden keine Versandkosten berechnet und keine Methoden gezeigt.
- **`show_shipping()` ist false:** Die Adressvoraussetzungen (oder allgemeine Konfiguration) sind nicht erfüllt. Führt zur sofortigen Rückkehr mit leerem Array (Auswirkung siehe Schwerpunkt Fall 27).
- **Keine Zone gefunden:** Fallback auf die globale Zone (ID 0). Wenn dort keine Methoden aktiv sind, bleibt das Raten-Array leer. Das Template zeigt dann `woocommerce_no_shipping_available_html`.
- **Ratenberechnung wirft Exception:** Innerhalb von WooCommerce kaum abgefangen. Exceptions von Fremd-APIs in `calculate_shipping()` einer Methode führen entweder zur Nicht-Anlage der Rate (`add_rate()` wird übersprungen) oder lassen den gesamten Totals-Lauf abstürzen.

---

## 7. Typische Störquellen

- **Zonenüberlappungen:** Die rohe SQL-Abfrage in Schritt 8 verwendet `LIMIT 1` sortiert nach `zone_order`. Liegt ein Kunde in zwei Zonen (z.B. PLZ-Zone und Länder-Zone) gewinnt strikt die Ordnung; die zweite Zone wird verworfen.
- **Drittanbieter in Schritt 12 (`woocommerce_package_rates`):** Dieser Filter erwartet die Rückgabe eines Arrays. Geben schlecht geschriebene Plugins `null` zurück oder manipulieren die Keys falsch, verschwinden alle Raten lautlos.
- **Veralteter Session-Cache:** Wird die Versandkonfiguration im Backend geändert und der Transient `shipping` nicht sauber invalidiert, lesen aktive Sessions weiter die alten Preise aus Schritt 5.
- **Legacy-Methoden:** Wenn in `class-wc-shipping.php:139` alte Non-Zone-Methoden aktiviert sind, tauchen Raten unabhängig von der Zonen-Berechnung auf.

---

## 8. Bezug zum Fehlerkatalog

| Schritt-Nr | Fall-Nr | Kurzbeschreibung |
|---|---|---|
| 2 | 27 | Keine Versandmethode verfügbar - Checkout blockiert ohne Meldung. |
| 3 | 29 | Digitale und physische Produkte gemischt, Versandpflicht falsch interpretiert. |
| 7, 8 | 26 | Überlappende oder falsch sortierte Versandzonen verschlucken Methoden. |
| 10 | 19 | Rundungsfehler bei Prozentrabatt und Netto/Brutto-Umrechnung. |
| 10 | 23 | Versandmethode auf Steuerstatus "keine Steuer", weswegen Versandsteuer ausfällt. |
| 10 | 24 | Steuerbasis auf Shop-Adresse statt Lieferadresse (Local-Pickup-Kopplung). |
| 12 | 35 | Preis-Manipulation über API durch unsicheren Fremdcode-Hook. |
| 16 | 20 | Free-Shipping-Schwelle wird vor Rabattabzug geprüft. |

---

## 9. Schwerpunkt Fall 27: Die vier Abbruchgründe von `WC_Cart::show_shipping()`

Die Methode `WC_Cart::show_shipping()` (`plugins/woocommerce/includes/class-wc-cart.php:1795-1860`) fungiert als Torwächter der Versandberechnung. Sie entscheidet, ob überhaupt gerechnet wird. Sie liefert nur ein binäres `true/false`, bündelt aber vier radikal unterschiedliche fachliche Gründe, warum der Versand abgebrochen werden soll.

Das Template wertet nur aus, ob Raten existieren. Sind keine da (weil diese Funktion `false` geliefert hat), rät es die Ursache, was aus Nutzersicht oft in der falschen Meldung "Bitte Adresse eingeben" resultiert, auch wenn ein Konfigurationsfehler vorliegt.

Dies sind die vier Zustände (und die Datenquellen), die den Abbruch auslösen:

### Grund 1: Versand global deaktiviert
* **Datenquelle:** `get_option('woocommerce_ship_to_countries')` ausgewertet durch `wc_shipping_enabled()`.
* **Codestelle:** `class-wc-cart.php:1797`
* **Zustand:** Der Shop hat den kompletten Versand abgeschaltet. Eine Berechnung ist weder möglich noch gewollt.

### Grund 2: Keine Versandmethoden konfiguriert
* **Datenquelle:** `wc_get_shipping_method_count( true )` (Liest `woocommerce_shipping_zone_methods` und Transient `wc_shipping_method_count`).
* **Codestelle:** `class-wc-cart.php:1797`
* **Zustand:** Der Versand ist zwar global aktiv, der Shopbetreiber hat aber keine einzige Methode in irgendeiner Zone angelegt. (Fall 27 Relevanz: Hier blockiert der Checkout strukturell aufgrund eines Konfigurationsfehlers).

> **Wichtige Praezisierung (verifiziert):** Dieses Tor greift nur bei *gar keiner angelegten* Methode. Die Signatur lautet `wc_get_shipping_method_count( $include_legacy = false, $enabled_only = false )` (`wc-core-functions.php:1650`). Das `true` an Position 1 setzt `include_legacy`, **nicht** `enabled_only`. Gezaehlt wird folglich `enabled + disabled + legacy` (`wc-core-functions.php:1694-1706`).
>
> Ein Shop mit angelegten, aber **saemtlich deaktivierten** Methoden passiert das Tor also. Die Schritte 3 bis 18 laufen durch, es kommen null Raten zurueck, und das Template zeigt `woocommerce_no_shipping_available_html`. Das ist der stillste Weg in Fall 27: `show_shipping()` kann diesen Zustand strukturell nicht melden, und die Admin-Warnung `WC_Admin_Notices::no_shipping_methods_notice()` (`includes/admin/class-wc-admin-notices.php:482-496`) ebenfalls nicht, weil sie dieselbe Zaehlung ohne `enabled_only` verwendet.
>
> Genau diese Luecke ist die Daseinsberechtigung von Waechter 27, siehe `guardians/27-no-shipping-method/contract.md`.

### Grund 3: Warenkorb leer
* **Datenquelle:** `$this->get_cart_contents()` (Laufzeit, leeres Array).
* **Codestelle:** `class-wc-cart.php:1797`
* **Zustand:** Regulärer Zustand vor dem Kauf. Kein Fehler, aber Rechnen ist sinnlos.

### Grund 4: Adresse unvollständig (und zwingend für Kalkulation gefordert)
* **Datenquelle:** `get_option( 'woocommerce_shipping_cost_requires_address' ) === 'yes'`, Locale-Prüfung (`WC()->countries->get_address_fields()`), sowie der Session-Zustand von `WC_Customer`.
* **Codestelle:** `class-wc-cart.php:1812-1849` (Shortcode) bzw. `1853` (Store-API-Delegation).
* **Zustand:** Es fehlen Land, gefordertes Bundesland oder geforderte PLZ. (Fall 27 Relevanz: Da die Funktion genau denselben Wert `false` wie Grund 2 liefert, kann die UI Grund 2 und 4 nicht unterscheiden).

**Ableitung für den Adapter-Vertrag:**
Ein Wächter auf Ebene 1 muss die Konfigurationsaspekte (Grund 1 und Grund 2) von der Laufzeit (Grund 3 und Grund 4) isolieren. Er liest die Schalter und die `wp_woocommerce_shipping_zone_methods` völlig unabhängig vom Warenkorbzustand.

---

## 9b. Auswirkung auf die Kundensicht

Aus dem Quelltext abgeleitet. Ein Nachweis am laufenden Shop steht aus.

**1. Der Torwaechter laesst den schlimmsten Fall durch.** Bei ausschliesslich
deaktivierten Methoden liefert `show_shipping()` **true**, weil
`wc_get_shipping_method_count( true )` auch deaktivierte Methoden mitzaehlt
(`class-wc-cart.php:1797`, Begruendung in Abschnitt 9, Grund 2). Die
Berechnung laeuft komplett durch und kommt mit null Raten zurueck. Im
Checkout gibt das Template dann `woocommerce_no_shipping_available_html`
aus - "There are no shipping options available. Please ensure that your
address has been entered correctly..."
(`templates/cart/cart-shipping.php:67`; auf der Warenkorbseite greift der
Zweig `woocommerce_cart_no_shipping_available_html`, `:79`). Die Meldung
schiebt die Ursache auf die Adresse des Kunden, obwohl die Konfiguration des
Shops schuld ist. Weder `show_shipping()` noch die Admin-Warnung koennen
diesen Zustand melden (Beleg in
`guardians/27-no-shipping-method/contract.md`, Abschnitt 4).

**2. `needs_shipping()` haengt am selben fehlerhaften Zaehler.**
`class-wc-cart.php:1765` ruft ebenfalls `wc_get_shipping_method_count( true )`
auf. Bei null angelegten Methoden meldet der Warenkorb daher, er brauche
ueberhaupt keinen Versand - obwohl er ein versandpflichtiges Produkt enthaelt.
Folge: auch `needs_shipping_address()` (`:1787`) wird false, die Adressfelder
verschwinden, und der Versandblock der Summentabelle wird gar nicht erst
gerendert (`templates/cart/cart-totals.php:41` verlangt `needs_shipping()`
**und** `show_shipping()`). Der Shop verhaelt sich wie ein reiner
Download-Shop.

**3. Der Fehler ist aus Betreibersicht unsichtbar.** Sitzt der Betreiber in
Deutschland und testet mit deutscher Adresse, sieht er bei einer Luecke fuer
die Schweiz nichts Auffaelliges. Nur der Schweizer Kunde laeuft in die
Sackgasse. Das ist der Grund, warum dieser Fall einen Waechter braucht und
nicht durch Ausprobieren gefunden wird.

---

## 10. Offene Fragen

- **Unterschied im Checkout-Block:** Wenn der Store-API-Pfad benutzt wird, greifen zusätzliche Filter (z. B. `remove_shipping_if_no_address()`), die im Shortcode-Weg via `show_shipping()` ignoriert werden. Ob dadurch neue Abbruchgründe hinzukommen, ist noch zu verifizieren (Fall 32).
- **Gratisversand vs. Rabatte (Fall 20):** Schritt 16 wählt aus. Wie genau sich `get_displayed_subtotal()` zum Zeitpunkt von Schritt 10 verhält, wenn parallel Coupon-Rabatte auf die Gesamtsumme wirken, muss im Detail-Ablauf "Gutscheine" geklärt werden.

---

## 11. Quellen

- `plugins/woocommerce/includes/class-wc-cart.php`
- `plugins/woocommerce/includes/class-wc-cart-totals.php`
- `plugins/woocommerce/includes/class-wc-shipping.php`
- `plugins/woocommerce/includes/class-wc-shipping-zones.php`
- `plugins/woocommerce/includes/class-wc-shipping-zone-data-store.php`
- `plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php`
- `plugins/woocommerce/includes/wc-cart-functions.php`
- `analysis/_domain/cart/domain-map.md`
- `analysis/_domain/cart/error-catalog.md`
