# Domaenen-Karte: Warenkorb

| Feld | Wert |
|---|---|
| Domaene | Warenkorb |
| Stufe | 1 (Domaenen-Karte nach VORGEHEN.md) |
| Datum | 2026-08-08 |
| Stand | Erstfassung. Schwerpunkt Versandberechnung. Alles ausserhalb der Versandberechnung nur als Tabellenzeile erfasst, nicht vertieft. |
| Analysiertes System | WooCommerce, `plugins/woocommerce/` |

Alle Pfade in diesem Dokument sind relativ zum Repo-Root.
Schichtbegriffe: Frontend, Eintrittspunkt, Domaenenlogik, Persistenz,
Erweiterungspunkt. Zusaetzlich `Hilfsfunktion` fuer globale Funktionen
(Schichtentabelle in `.gemini/prompts/domain-map-prompt.md`).

---

## 1. Domaenendefinition

### 1.1 Kern

Verwaltung des Warenkorbinhalts, Bildung der Versandpakete, Ermittlung und
Auswahl der Versandmethoden, Berechnung aller Summen und Persistenz dieses
Zustands ueber die Session.

Die Domaenengrenze wird hier **weiter** gezogen als in
`analysis/_domain/cart/full-analysis.md` (dort Abschnitt 1, Zeilen 14-18).
Begruendung: Dort wird `WC_Shipping` explizit ausgeschlossen ("der Warenkorb
baut nur Pakete"). Der Code widerspricht dem: `WC_Cart::calculate_shipping()`
ruft `WC()->shipping()->calculate_shipping()` direkt auf und uebernimmt das
Ergebnis in die eigenen Totals
(`plugins/woocommerce/includes/class-wc-cart.php:1597-1610`), und
`WC_Cart_Totals::get_shipping_from_cart()` ruft seinerseits
`WC_Cart::calculate_shipping()` auf
(`plugins/woocommerce/includes/class-wc-cart-totals.php:352`). Der Aufruf ist
zyklisch und synchron; eine Trennung existiert nur nominell. Der
Erstwaechter-Fall 27 ("Keine Versandmethode verfuegbar") liegt vollstaendig in
diesem ausgeschlossenen Bereich. Die Abgrenzung der Gesamtanalyse ist damit
fuer das Produktziel unbrauchbar und wird hier korrigiert.

### 1.2 Use Cases (Nutzersicht)

| Nr | Use Case | Versandbezug |
|---|---|---|
| U1 | Produkt in den Warenkorb legen | mittelbar (Paketinhalt aendert sich) |
| U2 | Menge aendern / Produkt entfernen / wiederherstellen | mittelbar |
| U3 | Warenkorb leeren | ja (Ratencache wird verworfen) |
| U4 | Gutschein anwenden und entfernen | ja (Gratisversand-Gutschein) |
| U5 | Lieferadresse im Warenkorb schaetzen ("Versandkosten berechnen") | ja |
| U6 | Versandmethode auswaehlen | ja |
| U7 | Verfuegbare Versandkosten sehen (Cart-Seite, Mini-Cart, Block) | ja |
| U8 | Summen sehen (Zwischensumme, Steuer, Versand, Gesamt) | ja |
| U9 | Warenkorb aus Session laden und speichern | ja (gewaehlte Methode, Ratencache) |
| U10 | Warenkorb ueber Store API lesen/aendern (Blocks, Headless) | ja |

10 Use Cases, innerhalb der Faustregel 5-12 aus VORGEHEN.md Zeile 33.

### 1.3 Abgrenzung - was gehoert NICHT dazu

| Nicht Teil der Domaene | Begruendung |
|---|---|
| Bestellung / `WC_Checkout`, `WC_Order`, `WC_Order_Item_Shipping` | Konsumiert den fertigen Warenkorb. Die Versandposition wird dort nur eingefroren, nicht mehr ermittelt. `plugins/woocommerce/includes/class-wc-order-item-shipping.php` ist reine Order-Persistenz. |
| Versandzonen-**Verwaltung** im Admin (Anlegen, Sortieren, Methodeninstanz konfigurieren) | Eigene Domaene "Versandkonfiguration". Beruehrt den Warenkorb nur ueber Optionen und Cache-Invalidierung. Aufgenommen sind nur die Stellen, die Cache verwerfen, weil sie Warenkorbverhalten aendern. |
| Versandlabel, Sendungsverfolgung, Fulfillment (`src/Admin/Features/Fulfillments/**`, `src/Internal/Admin/ShippingLabelBanner*.php`) | Nachgelagert zur Bestellung, kein Einfluss auf Ratenermittlung. |
| Produktstammdaten (Gewicht, Masse, Versandklasse am Produkt) | Eingabedaten aus Domaene "Produkt". Der Warenkorb liest sie nur. Fehlerkatalog Fall 4 und Fall 2 liegen an dieser Grenze. |
| Steuersatz-Stammdaten (`WC_Tax::find_rates`, Tax-Settings) | Eigene Domaene. Aufgenommen ist nur der versandspezifische Zugriff (`get_shipping_tax_rates`). |
| Payment / Zahlarten | Beruehrt den Warenkorb erst nach Abschluss der Versandwahl. |
| Fremd-Plugins im Repo (`plugins/packlink-pro-shipping`, `plugins/woocommerce-services`, `plugins/woocommerce-payments/vendor/**/subscriptions-core`, `plugins/woocommerce-paypal-payments`) | Analyseobjekt zweiter Ordnung. Sie haengen sich in die hier erfassten Erweiterungspunkte ein, sind aber nicht Kern der Domaene. Ob sie in dieser Instanz aktiv sind, wurde nicht geprueft (siehe Offene Fragen). |

Unscharfe Grenzen, bewusst benannt:
- **Kunde/Adresse**: `WC_Customer` ist formal Kundendomaene, liefert aber die
  einzige Eingabe fuer die Zonenermittlung
  (`plugins/woocommerce/includes/class-wc-cart.php:1680-1687`). Hier als
  Domaenenlogik der Versandberechnung gefuehrt.
- **Session**: `WC_Session_Handler` ist Plattforminfrastruktur, haelt aber den
  Ratencache und die gewaehlte Methode. Hier als Persistenz gefuehrt.

---

## 2. Beteiligte Dateien

Legende Versandbezug: **ja** = Ermittlung, Auswahl oder Anzeige von
Versandmethoden/-kosten. **nein** = Warenkorb-Domaene ohne Versandbezug.

### 2.1 Domaenenlogik

| Datei | Schicht | Rolle | Versand |
|---|---|---|---|
| `plugins/woocommerce/includes/class-wc-cart.php` | Domaenenlogik | Zentrales Warenkorbobjekt; baut die Versandpakete (`get_shipping_packages()` Z. 1661) und uebernimmt Kosten/Steuern der gewaehlten Raten (`calculate_shipping()` Z. 1584-1612). | ja |
| `plugins/woocommerce/includes/class-wc-cart-totals.php` | Domaenenlogik | Berechnungs-Engine; ruft ueber `get_shipping_from_cart()` (Z. 336-353) zurueck in den Cart und setzt `shipping_total`/`shipping_tax` (Z. 855-862). | ja |
| `plugins/woocommerce/includes/class-wc-shipping.php` | Domaenenlogik | Orchestriert Ratenermittlung je Paket: Zone laden, Methoden instanziieren, Raten sammeln, Session-Cache schreiben (`calculate_shipping_for_package()` Z. 311-419). | ja |
| `plugins/woocommerce/includes/class-wc-shipping-zones.php` | Domaenenlogik | Statische Fassade; `get_zone_matching_package()` (Z. 159-173) mit Objektcache `shipping_zones`. | ja |
| `plugins/woocommerce/includes/class-wc-shipping-zone.php` | Domaenenlogik | Zonenobjekt; instanziiert die Methodenobjekte der Zone und sortiert sie (`get_shipping_methods()` Z. 159-208). | ja |
| `plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php` | Domaenenlogik | Basisklasse jeder Versandmethode; `get_rates_for_package()` Z. 256, `add_rate()` Z. 291-376 (inkl. Steuerberechnung), `is_available()` Z. 435-457. | ja |
| `plugins/woocommerce/includes/class-wc-shipping-rate.php` | Domaenenlogik | Wertobjekt einer einzelnen Rate; `get_shipping_tax()` (Z. 327-337) liefert 0 bei VAT-Befreiung. | ja |
| `plugins/woocommerce/includes/shipping/flat-rate/class-wc-shipping-flat-rate.php` | Domaenenlogik | Pauschalversand inkl. Versandklassen-Aufschlag (Z. 163-234). | ja |
| `plugins/woocommerce/includes/shipping/free-shipping/class-wc-shipping-free-shipping.php` | Domaenenlogik | Gratisversand; Verfuegbarkeit ueber Mindestbetrag und/oder Gutschein (`is_available()` Z. 160-213). | ja |
| `plugins/woocommerce/includes/shipping/local-pickup/class-wc-shipping-local-pickup.php` | Domaenenlogik | Abholung; erzeugt Rate ohne Zieladressbezug (Z. 74 ff.). | ja |
| `plugins/woocommerce/includes/shipping/legacy-flat-rate/class-wc-shipping-legacy-flat-rate.php` | Domaenenlogik | Legacy-Methode ohne Zonenunterstuetzung; wird nur geladen, wenn Option aktiv (`class-wc-shipping.php:139-150`). | ja |
| `plugins/woocommerce/includes/shipping/legacy-free-shipping/class-wc-shipping-legacy-free-shipping.php` | Domaenenlogik | wie vor | ja |
| `plugins/woocommerce/includes/shipping/legacy-international-delivery/class-wc-shipping-legacy-international-delivery.php` | Domaenenlogik | wie vor | ja |
| `plugins/woocommerce/includes/shipping/legacy-local-delivery/class-wc-shipping-legacy-local-delivery.php` | Domaenenlogik | wie vor | ja |
| `plugins/woocommerce/includes/shipping/legacy-local-pickup/class-wc-shipping-legacy-local-pickup.php` | Domaenenlogik | wie vor | ja |
| `plugins/woocommerce/src/Blocks/Shipping/PickupLocation.php` | Domaenenlogik | Blocks-Abholmethode; erzeugt je konfiguriertem Standort eine Rate (Z. 96-125), `is_available()` Z. 126. | ja |
| `plugins/woocommerce/includes/class-wc-customer.php` | Domaenenlogik | Liefert Lieferadresse als einzige Eingabe der Zonenermittlung; `has_full_shipping_address()` Z. 287, `get_taxable_address()` Z. 187-223 (Local-Pickup-Sonderfall Z. 191-193), `set_shipping_location()` Z. 991. | ja |
| `plugins/woocommerce/includes/class-wc-countries.php` | Domaenenlogik | `get_shipping_countries()` (Z. 356-380) begrenzt, wohin geliefert werden darf; Grundlage von `is_package_shippable()`. | ja |
| `plugins/woocommerce/includes/class-wc-tax.php` | Domaenenlogik | Versandsteuer: `get_shipping_tax_rates()` Z. 584-643, `calc_shipping_tax()` Z. 86-102, `find_shipping_rates()` Z. 277-290, `get_shipping_tax_class_from_cart_items()` Z. 653. | ja |
| `plugins/woocommerce/src/Utilities/ShippingUtil.php` | Domaenenlogik | Reduziert berechnete Pakete auf die je Paket gewaehlte Rate (Z. 20-33). | ja |
| `plugins/woocommerce/src/StoreApi/Utilities/CartController.php` | Domaenenlogik | Store-API-Fassade: `get_shipping_packages()` Z. 890-913, `select_shipping_rate()` Z. 916-930, Cart-Hash inkl. Versandanteil Z. 852. | ja |
| `plugins/woocommerce/src/StoreApi/Utilities/LocalPickupUtils.php` | Domaenenlogik | Klassifiziert Methoden als Abholung; steuert Ratenfilterung und Vorauswahl (Z. 55, 76-95, 103). | ja |
| `plugins/woocommerce/src/Blocks/Utils/CartCheckoutUtils.php` | Domaenenlogik | `shipping_methods_exist()` (Z. 89-102) entscheidet, ob im Block-Kontext ueberhaupt vorausgewaehlt wird. | ja |
| `plugins/woocommerce/includes/class-wc-cart-fees.php` | Domaenenlogik | Registry fuer Zusatzgebuehren. | nein |
| `plugins/woocommerce/includes/legacy/class-wc-legacy-cart.php` | Domaenenlogik | Deprecated-Schicht des Cart-Objekts. | nein |
| `plugins/woocommerce/includes/legacy/class-wc-legacy-shipping-zone.php` | Domaenenlogik | Deprecated-Schicht der Zone (`get_zone_id()` Z. 21-24, `read()` Z. 32-36). | ja |

### 2.2 Eintrittspunkte

| Datei | Schicht | Rolle | Versand |
|---|---|---|---|
| `plugins/woocommerce/includes/class-wc-ajax.php` | Eintrittspunkt | `update_shipping_method()` (Z. 334-354) schreibt die Auswahl in die Session; `update_order_review()` (Z. 396-450) tut dasselbe im Checkout und setzt Adressen. | ja |
| `plugins/woocommerce/includes/shortcodes/class-wc-shortcode-cart.php` | Eintrittspunkt | Verarbeitet das Versandrechner-Formular: Nonce Z. 82-86, Adressuebernahme Z. 29-54, `do_action( 'woocommerce_calculated_shipping' )` Z. 59. | ja |
| `plugins/woocommerce/src/StoreApi/Routes/V1/CartSelectShippingRate.php` | Eintrittspunkt | REST-Route `/cart/select-shipping-rate` (Z. 32); bricht mit 404 ab, wenn Versand global aus ist (Z. 72-73). | ja |
| `plugins/woocommerce/src/StoreApi/Routes/V1/CartUpdateCustomer.php` | Eintrittspunkt | REST-Route, die die Lieferadresse setzt und damit die Zonenermittlung ausloest (Z. 136-176). | ja |
| `plugins/woocommerce/src/StoreApi/Routes/V1/Cart.php` | Eintrittspunkt | Liefert den Warenkorb inkl. `shipping_rates`. | ja |
| `plugins/woocommerce/src/StoreApi/Routes/V1/AbstractCartRoute.php` | Eintrittspunkt | Gemeinsame Basis aller Cart-Routen (Token, Nonce, Totals-Neuberechnung). | ja |
| `plugins/woocommerce/includes/class-wc-form-handler.php` | Eintrittspunkt | Nicht-AJAX-Formulare des Warenkorbs; Adressspeicherung im Konto (Z. 80 ff.). | nein |
| `plugins/woocommerce/src/StoreApi/Routes/V1/CartAddItem.php`, `CartItems.php`, `CartItemsByKey.php`, `CartUpdateItem.php`, `CartRemoveItem.php` | Eintrittspunkt | Inhaltsaenderungen ueber Store API. | nein |
| `plugins/woocommerce/src/StoreApi/Routes/V1/CartApplyCoupon.php`, `CartRemoveCoupon.php`, `CartCoupons.php`, `CartCouponsByCode.php` | Eintrittspunkt | Gutscheinverwaltung; mittelbar versandrelevant ueber Gratisversand-Gutschein. | nein |
| `plugins/woocommerce/includes/widgets/class-wc-widget-cart.php` | Eintrittspunkt | Legacy-Mini-Cart-Widget. | nein |
| `plugins/woocommerce/includes/admin/settings/class-wc-settings-shipping.php` | Eintrittspunkt | Admin-Speicherung der Versandeinstellungen; verwirft den Versand-Cache (Z. 246). | ja |
| `plugins/woocommerce/src/Internal/RestApi/Routes/V4/ShippingZoneMethod/ShippingZoneMethodService.php` | Eintrittspunkt | V4-REST-Service fuer Zonenmethoden; verwirft den Versand-Cache (Z. 176). | ja |

### 2.3 Persistenz

| Datei | Schicht | Rolle | Versand |
|---|---|---|---|
| `plugins/woocommerce/includes/class-wc-cart-session.php` | Persistenz | Laedt/speichert den Warenkorb; loescht bei Zerstoerung `chosen_shipping_methods`, `shipping_method_counts`, `previous_shipping_methods` (Z. 322-325, 422-425) und alle `shipping_for_package_*` (Z. 694-706). | ja |
| `plugins/woocommerce/includes/class-wc-session-handler.php` | Persistenz | Traegt den Ratencache (`shipping_for_package_N`) und die gewaehlte Methode in `wp_woocommerce_sessions`. | ja |
| `plugins/woocommerce/includes/data-stores/class-wc-shipping-zone-data-store.php` | Persistenz | `get_zone_id_from_package()` (Z. 305-350) ist das eigentliche Zonen-Matching per SQL; invalidiert Caches (Z. 38-39, 63-64, 198-199). | ja |
| `plugins/woocommerce/includes/interfaces/class-wc-shipping-zone-data-store-interface.php` | Persistenz | Vertrag des Zonen-Datastores. | ja |
| `plugins/woocommerce/includes/class-wc-cache-helper.php` | Persistenz | Transient-Version `shipping` und Cachegruppe `shipping_zones`; steuert Gueltigkeit des Ratencaches. | ja |

### 2.4 Frontend

| Datei | Schicht | Rolle | Versand |
|---|---|---|---|
| `plugins/woocommerce/templates/cart/cart-shipping.php` | Frontend | Zeigt Raten je Paket, sonst eine von vier Ersatzmeldungen (Z. 60-86). Hier entsteht die Nutzersicht von Fall 27. | ja |
| `plugins/woocommerce/templates/cart/shipping-calculator.php` | Frontend | Formular fuer Land/Bundesland/Ort/PLZ, Nonce Z. 92. | ja |
| `plugins/woocommerce/templates/cart/cart-totals.php` | Frontend | Bindet Versandzeile und Rechner ein (Z. 53). | ja |
| `plugins/woocommerce/templates/cart/cart.php`, `cart-empty.php`, `cart-item-data.php`, `mini-cart.php` | Frontend | Warenkorbdarstellung. | nein |
| `plugins/woocommerce/assets/js/frontend/cart.js` | Frontend | Sendet Methodenwechsel per AJAX (`shipping_method_selected` Z. 296-330) und den Rechner ab (Z. 339 ff.). | ja |
| `plugins/woocommerce/assets/js/frontend/cart-fragments.js` | Frontend | Mini-Cart-Fragmente. | nein |
| `plugins/woocommerce/src/Blocks/BlockTypes/CartOrderSummaryShippingBlock.php` | Frontend | Serverseitige Registrierung des Versandblocks im Cart-Block. | ja |
| `plugins/woocommerce/src/Blocks/BlockTypes/CheckoutShippingMethodsBlock.php`, `CheckoutShippingMethodBlock.php`, `CheckoutOrderSummaryShippingBlock.php` | Frontend | Blockregistrierungen der Versandauswahl im Checkout-Block. | ja |
| `plugins/woocommerce/assets/client/blocks/cart-frontend.js`, `wc-cart-checkout-base-frontend.js`, `wc-shipping-method-pickup-location.js` | Frontend | Ausgelieferte React-Bundles (minifiziert, kein Quelltext im Repo). | ja |
| `plugins/woocommerce/templates/templates/page-cart.html`, `templates/blockified/page-cart.html`, `templates/parts/mini-cart.html` | Frontend | Blockvorlagen der Warenkorbseite. | nein |
| `plugins/woocommerce/includes/admin/views/html-notice-no-shipping-methods.php` | Frontend (Admin) | Warnhinweis "keine Versandmethode konfiguriert". | ja |

### 2.5 Hilfsfunktionen

| Datei | Schicht | Rolle | Versand |
|---|---|---|---|
| `plugins/woocommerce/includes/wc-cart-functions.php` | Hilfsfunktion | Auswahl und Anzeige der Raten: `wc_cart_totals_shipping_html()` Z. 237-270, `wc_cart_totals_shipping_method_label()` Z. 380, `wc_get_chosen_shipping_method_for_package()` Z. 446-486, `wc_get_default_shipping_method_for_package()` Z. 497-575, `wc_shipping_methods_have_changed()` Z. 585-599, `wc_get_chosen_shipping_method_ids()` Z. 419. | ja |
| `plugins/woocommerce/includes/wc-core-functions.php` | Hilfsfunktion | `wc_get_shipping_method_count()` Z. 1650-1700 (Transient), `wc_postcode_location_matcher()` Z. 1597, `wc_shipping_zone_method_order_uasort_comparison()` Z. 1753. | ja |
| `plugins/woocommerce/includes/wc-conditional-functions.php` | Hilfsfunktion | `wc_shipping_enabled()` Z. 408 - globaler An/Aus-Schalter. | ja |
| `plugins/woocommerce/includes/wc-formatting-functions.php` | Hilfsfunktion | `wc_normalize_postcode()` Z. 1073, `wc_make_numeric_postcode()` Z. 1282 - PLZ-Normalisierung fuer das Zonen-Matching. | ja |
| `plugins/woocommerce/includes/wc-order-functions.php` | Hilfsfunktion | `wc_ship_to_billing_address_only()` Z. 545 - entscheidet, ob die Rechnungsadresse die Zone bestimmt. | ja |
| `plugins/woocommerce/includes/wc-template-functions.php` | Hilfsfunktion | `woocommerce_shipping_calculator()` Z. 2633-2645. | ja |
| `plugins/woocommerce/includes/class-woocommerce.php` | Hilfsfunktion | `WC()->shipping()` Z. 1317-1319 als Zugang zur Singleton-Instanz. | ja |
| `plugins/woocommerce/includes/class-wc-admin-notices.php` | Hilfsfunktion (Admin) | `no_shipping_methods_notice()` Z. 482-496 - zeigt den Hinweis nur, wenn Produkte existieren und `wc_get_shipping_method_count()` 0 ist. | ja |

### 2.6 Erweiterungspunkte (Auswahl, versandrelevant)

Vollstaendiger Index gehoert in Stufe 3. Hier nur die Filter/Actions, die die
Ratenermittlung direkt veraendern koennen.

| Hook | Typ | Ausgeloest in | Wirkung |
|---|---|---|---|
| `woocommerce_cart_shipping_packages` | Filter | `class-wc-cart.php:1669-1692` | Paketaufteilung und Zieladresse frei ersetzbar. |
| `woocommerce_shipping_packages` | Filter | `class-wc-shipping.php:278` | Pakete nach der Berechnung veraenderbar; Kern nutzt ihn selbst zweimal (`ShippingController.php:80, 83`). |
| `woocommerce_package_rates` | Filter | `class-wc-shipping.php:398` | Ratenliste je Paket; kann auf leer gesetzt werden. Nicht-Array wird auf leer zurueckgesetzt (Z. 402-404). |
| `woocommerce_shipping_methods` | Filter | `class-wc-shipping.php:152` | Registrierung zusaetzlicher Methodenklassen. |
| `woocommerce_load_shipping_methods` | Action | `class-wc-shipping.php:185` | Manuelle Registrierung je Paket. |
| `woocommerce_shipping_zone_shipping_methods` | Filter | `class-wc-shipping-zone.php:208` | Methoden einer Zone austauschbar. |
| `woocommerce_get_zone_criteria` | Filter | `class-wc-shipping-zone-data-store.php:341` | SQL-Kriterien des Zonen-Matchings. |
| `woocommerce_shipping_<id>_is_available` | Filter | `abstract-wc-shipping-method.php:456`, `free-shipping:212` | Verfuegbarkeit einer Methode. |
| `woocommerce_shipping_method_add_rate` / `_add_rate_args` | Filter | `abstract-wc-shipping-method.php:376, 292` | Einzelne Rate vor Uebernahme. |
| `woocommerce_shipping_chosen_method` | Filter | `wc-cart-functions.php:532, 574` | Vorauswahl der Methode. |
| `woocommerce_shipping_method_chosen` | Action | `wc-cart-functions.php:483` | Feuert, wenn die Auswahl neu gesetzt wurde. |
| `woocommerce_cart_needs_shipping` | Filter | `class-wc-cart.php:1778` | Ob Versand ueberhaupt noetig ist. |
| `woocommerce_cart_ready_to_calc_shipping` | Filter | `class-wc-cart.php:1809, 1859 ff.` | Ob gerechnet und angezeigt wird. |
| `woocommerce_shipping_prices_include_tax` | Filter | `abstract-wc-shipping-method.php:334`, `class-wc-tax.php:93` | Brutto/Netto-Deutung der Versandkosten. |
| `woocommerce_shipping_tax_class` | Filter | `class-wc-tax.php:619` | Steuerklasse des Versands. |
| `woocommerce_customer_taxable_address` | Filter | `class-wc-customer.php:222` | Steuerbasisadresse; Kern haengt sich selbst ein (`ShippingController.php:78`). |
| `woocommerce_apply_base_tax_for_local_pickup` | Filter | `class-wc-customer.php:191` | Schaltet bei Abholung auf Shop-Adresse als Steuerbasis. |
| `woocommerce_calculated_shipping` | Action | `class-wc-shortcode-cart.php:59` | Nach Rechner-Absenden. |
| `woocommerce_store_api_cart_select_shipping_rate` | Action | `CartSelectShippingRate.php:108` | Nach Ratenwahl per Store API. |
| `woocommerce_cart_no_shipping_available_html`, `woocommerce_no_shipping_available_html`, `woocommerce_shipping_may_be_available_html`, `woocommerce_shipping_not_enabled_on_cart_html` | Filter | `templates/cart/cart-shipping.php:62, 64, 67, 79` | Text der vier Ersatzmeldungen. |

---

## 3. Schwerpunkt: Versandberechnung

### 3.1 Aufrufkette (klassischer Pfad)

| # | Ort | Was passiert |
|---|---|---|
| 1 | `class-wc-cart.php:1584` `calculate_shipping()` | Setzt Versandsummen auf 0, `has_calculated_shipping = false`. |
| 2 | `class-wc-cart.php:1592` | Abbruch, wenn `needs_shipping()` (Z. 1764) oder `show_shipping()` (Z. 1795) false. Ergebnis: leere Methodenliste, keine Meldung. |
| 3 | `class-wc-cart.php:1661` `get_shipping_packages()` | Baut ein Paket aus allen versandpflichtigen Positionen; Zieladresse aus `WC_Customer` (Z. 1680-1687); Filter `woocommerce_cart_shipping_packages` (Z. 1669). Nicht-Array oder leer wird zu `array()` (Z. 1694-1697). |
| 4 | `class-wc-shipping.php:255` `calculate_shipping()` | Iteriert Pakete; danach Filter `woocommerce_shipping_packages` (Z. 278). |
| 5 | `class-wc-shipping.php:311` `calculate_shipping_for_package()` | Prueft `is_package_shippable()` (Z. 291-299); bildet Paket-Hash inkl. Transient-Version `shipping` (Z. 336); liest Session-Cache `shipping_for_package_<key>` (Z. 332-333). |
| 6 | `class-wc-shipping.php:339` `load_shipping_methods( $package )` | Ermittelt die passende Zone (Z. 165) und laedt deren aktivierte Methoden (Z. 166). |
| 7 | `class-wc-shipping-zones.php:159` `get_zone_matching_package()` | Objektcache `shipping_zones` ueber md5(Land+Bundesland+PLZ) (Z. 163-170). |
| 8 | `class-wc-shipping-zone-data-store.php:305` `get_zone_id_from_package()` | SQL-Matching Land/Bundesland/Kontinent/PLZ, `ORDER BY zone_order ASC, zone_id ASC LIMIT 1` (Z. 344-349). Fallback Zone 0. |
| 9 | `abstract-wc-shipping-method.php:256` `get_rates_for_package()` | Prueft `is_available()` (Z. 435-457) und ruft `calculate_shipping()` der konkreten Methode. |
| 10 | `abstract-wc-shipping-method.php:291` `add_rate()` | Erzeugt `WC_Shipping_Rate`; berechnet Versandsteuer ueber `WC_Tax::get_shipping_tax_rates()` (Z. 320-321), nur wenn `is_taxable()` und Kosten > 0 (Z. 319). |
| 11 | `class-wc-shipping.php:370-388` | Optional Ausblenden aller Raten ausser Gratisversand und Abholung (`woocommerce_shipping_hide_rates_when_free`). |
| 12 | `class-wc-shipping.php:398` | Filter `woocommerce_package_rates`. |
| 13 | `class-wc-shipping.php:407-413` | Raten in die Session schreiben. |
| 14 | `ShippingUtil.php:20` ueber `class-wc-cart.php:1622` | Je Paket genau eine Rate auswaehlen. |
| 15 | `wc-cart-functions.php:446` `wc_get_chosen_shipping_method_for_package()` | Setzt die Auswahl neu, wenn nicht vorhanden, nicht mehr verfuegbar oder die Ratenanzahl sich geaendert hat (Z. 469). |
| 16 | `wc-cart-functions.php:497` `wc_get_default_shipping_method_for_package()` | Vorauswahl: erste Nicht-Abholrate; Abholung bleibt erhalten wenn bereits gewaehlt (Z. 547); Gratisversand-Gutschein erzwingt `free_shipping` (Z. 552-563). |
| 17 | `class-wc-cart.php:1599-1610` | Kosten und Steuern der gewaehlten Raten summieren. |
| 18 | `class-wc-cart-totals.php:855-862` | Uebernahme in die Gesamtsummen. |

### 3.2 Konfigurationseingaben (Kandidaten fuer Waechter Ebene 1)

| Option / Term | Gelesen in | Wirkung |
|---|---|---|
| `woocommerce_ship_to_countries`, `woocommerce_specific_ship_to_countries` | `class-wc-countries.php:356-380` | `disabled` liefert leere Laenderliste, damit ist jedes Paket mit Zielland unshippable. |
| `woocommerce_allowed_countries`, `woocommerce_specific_allowed_countries`, `woocommerce_all_except_countries` | `class-wc-countries.php:319-348` | Basis der Versandlaender. |
| `woocommerce_shipping_cost_requires_address` | `class-wc-cart.php:1801`, `wc-cart-functions.php:520`, `ShippingController.php:524` | Unterdrueckt Berechnung bzw. Nicht-Abholraten ohne vollstaendige Adresse. |
| `woocommerce_enable_shipping_calc` | `templates/cart/cart-shipping.php:61` | Steuert nur den Meldungstext, nicht die Berechnung. |
| `woocommerce_shipping_hide_rates_when_free` | `class-wc-shipping.php:370` | Entfernt kostenpflichtige Raten, sobald Gratisversand greift. |
| `woocommerce_shipping_tax_class` | `class-wc-tax.php:586`, `class-wc-cart-totals.php:342` | `inherit` leitet aus den Positionen ab, sonst feste Klasse. |
| `woocommerce_tax_based_on` | `class-wc-customer.php:188` | Steuerbasis Shop/Rechnung/Lieferung. Fehlerkatalog Fall 24. |
| `woocommerce_pickup_location_settings` | `LocalPickupUtils.php:58` | Aktivierung der Blocks-Abholung. |
| `woocommerce_<method>_settings` (legacy) | `class-wc-shipping.php:146-149` | Aktiviert Legacy-Methoden ohne Zonenbezug. |
| `woocommerce_shipping_debug_mode` | `class-wc-shipping.php:164, 338` | Umgeht den Ratencache und blendet den Zonennamen als Notice ein. |
| Tabelle `woocommerce_shipping_zone_methods` (`is_enabled`) | `wc-core-functions.php:1676-1678` | Zaehlung aktiver Methoden; Grundlage von `needs_shipping()` und `show_shipping()`. |
| Term-Taxonomie `product_shipping_class` | `class-wc-shipping.php:237-243`, `flat-rate:187-196` | Versandklassenaufschlaege. |

### 3.3 Zustand in der Session

| Schluessel | Geschrieben in | Bedeutung |
|---|---|---|
| `shipping_for_package_<n>` | `class-wc-shipping.php:407-413` | Ratencache je Paket, gueltig ueber `package_hash`. |
| `chosen_shipping_methods` | `wc-cart-functions.php:474`, `class-wc-ajax.php:351, 419`, `CartController.php:929` | Gewaehlte Methode je Paket. |
| `shipping_method_counts` | `wc-cart-functions.php:475` | Anzahl Raten beim letzten Setzen. Im Code als deprecated markiert ("Remove in 4.0.0", Z. 455) und trotzdem in der Neusetzungsbedingung Z. 469 verwendet. |
| `previous_shipping_methods` | `wc-cart-functions.php:597` | Ratenschluessel des letzten Requests, Basis fuer `wc_shipping_methods_have_changed()`. |

Cache-Invalidierung: `WC_Cache_Helper::get_transient_version( 'shipping', true )`
in `class-wc-shipping-zone.php:434, 458`,
`class-wc-shipping-zone-data-store.php:39, 64, 199`,
`class-wc-ajax.php:3298, 3742`,
`class-wc-settings-shipping.php:246`,
`class-wc-settings-tax.php:144`,
`ShippingController.php:430`,
`ShippingZoneMethodService.php:176`.

### 3.4 Zwei Pfade, ein Kern

| Aspekt | Klassisch (Shortcode/Templates) | Blocks / Store API |
|---|---|---|
| Kontextmarker | `WC_Cart::$cart_context = 'shortcode'` (`class-wc-cart.php:37`) | auf `'store-api'` gesetzt in `CartController.php:38` |
| Adresspruefung vor Berechnung | Feldweise ueber Locale (`class-wc-cart.php:1812-1849`) | `has_full_shipping_address()` (`class-wc-cart.php:1853`) |
| Vorauswahl | erste Rate (`wc-cart-functions.php:502`) | leer, wenn Nicht-Abholmethoden existieren (`wc-cart-functions.php:505`) |
| Abholung | Legacy `local_pickup` | zusaetzlich `PickupLocation.php`, Ratenfilter in `ShippingController.php:477-508` |
| Ausblenden ohne Adresse | ueber `show_shipping()` | zusaetzlich `remove_shipping_if_no_address()` (`ShippingController.php:519-550`), das im Shortcode-Kontext bewusst aussteigt (Z. 520-522) |

Dieselbe Konfiguration kann in beiden Pfaden unterschiedlich wirken. Das ist
kein Fehler im Sinne eines Bugs, aber eine Fehlerquelle bei der Diagnose und
der Grund fuer Fehlerkatalog Fall 32.

---

## 4. Hotspots

| # | Ort | Begruendung |
|---|---|---|
| H1 | `class-wc-shipping.php:311-419` `calculate_shipping_for_package()` | Konvergenzpunkt: Zonenwahl, Methodenladen, Steuer, Cache, zwei Filter und ein Konfigurationsschalter in einer Methode. Jede Ratenanomalie laeuft hier durch. |
| H2 | `class-wc-cart.php:1795-1860` `show_shipping()` | Vier voneinander unabhaengige Abbruchgruende (Versand aus, keine Methode, leerer Warenkorb, Adresse unvollstaendig) mit **einem** Rueckgabewert `false`. Der Aufrufer kann die Ursache nicht unterscheiden; das Template zeigt daher eine Ersatzmeldung, die die Ursache nur raet (`templates/cart/cart-shipping.php:60-86`). Kern von Fehlerkatalog Fall 27. |
| H3 | `wc-cart-functions.php:446-575` | Eine `get_`-Funktion mit Schreibzugriff auf drei Sessionschluessel (Z. 474-475) und einer Action (Z. 483). Auch `wc_shipping_methods_have_changed()` schreibt beim Lesen (Z. 597). Trockenlauf ist damit unmoeglich; jeder Waechter, der diese Funktion aufruft, veraendert Zustand. Fuer den Regelkern (CLAUDE.md, Aufrufkontext-Unabhaengigkeit) unbrauchbar. |
| H4 | `class-wc-shipping-zone-data-store.php:305-350` | Rohes SQL mit `LIMIT 1`. Ueberlappende Zonen fallen still unter den Tisch, die Reihenfolge entscheidet allein `zone_order`. Fehlerkatalog Fall 26. Der Filter `woocommerce_get_zone_criteria` (Z. 341) erlaubt Fremdcode, das WHERE beliebig zu erweitern. |
| H5 | Zweistufiger Cache: `shipping_zones` (Objektcache, `class-wc-shipping-zones.php:163-170`) und `shipping_for_package_*` (Session, `class-wc-shipping.php:332-338`) | Acht verschiedene Stellen invalidieren die Transient-Version (Abschnitt 3.3). Eine vergessene Invalidierung erzeugt veraltete Raten ohne sichtbare Ursache. Fehlerkatalog Faelle 18 und 11 sind strukturgleich. |
| H6 | `abstract-wc-shipping-method.php:291-376` `add_rate()` | Steuerberechnung, Brutto/Netto-Umwandlung (Z. 334-340) und Rundung (Z. 344) in einer Methode, gesteuert von zwei Filtern. Fehlerkatalog Faelle 19, 23. |
| H7 | `flat-rate:223` `if ( $has_costs )` | Ist das Kostenfeld leer und keine Versandklasse konfiguriert, wird **gar keine** Rate erzeugt. Aus Kundensicht identisch mit "keine Zone gefunden", aus Konfigurationssicht ein voellig anderer Fehler. Fehlerkatalog Faelle 6 und 27. |
| H8 | `free-shipping:177-192` | Der Mindestbetrag wird auf `get_displayed_subtotal()` geprueft; Rabatte werden nur abgezogen, wenn `ignore_discounts === 'no'` (Z. 180). Direkter Treffer fuer Fehlerkatalog Fall 20. |
| H9 | `ShippingController.php:75-85` | Der Kern haengt sich mit acht eigenen Callbacks in die eigenen Erweiterungspunkte, davon zweimal in `woocommerce_shipping_packages` mit unterschiedlicher Prioritaet (10 und 11). Fremdcode auf demselben Filter interagiert unvorhersehbar mit dieser Reihenfolge. |
| H10 | `class-wc-customer.php:187-223` `get_taxable_address()` | Die Wahl der Versandmethode aendert rueckwirkend die Steuerbasis (Local Pickup -> Shop-Adresse, Z. 191-193). Kopplung entgegen der Leserichtung des Ablaufs. Fehlerkatalog Faelle 23, 24. |

### Toter und veralteter Code

| Ort | Befund |
|---|---|
| `class-wc-shipping.php:445-447` | `sort_shipping_methods()` ist seit 2.6 deprecated und ruft nur noch `wc_deprecated_function()`. Toter Code. |
| `wc-cart-functions.php:455-456, 469, 472, 475` | `shipping_method_counts` ist im Kommentar als "Remove in 4.0.0" markiert, wird aber weiterhin geschrieben und in der Entscheidungslogik gelesen. |
| `includes/legacy/class-wc-legacy-shipping-zone.php` | Vollstaendig deprecated (Z. 21-36). |
| `includes/shipping/legacy-*/` (5 Klassen) | Methoden ohne Zonenunterstuetzung, werden nur bei gesetzter Option geladen (`class-wc-shipping.php:139-150`). Sie umgehen das Zonenmodell vollstaendig und sind eine haeufige, schwer sichtbare Ursache fuer unerwartete Raten. |
| `class-wc-shipping.php:293-295` | `is_package_shippable()` liefert bei leerem Zielland `true`. Raten werden also berechnet, bevor eine Adresse bekannt ist - der Schutz greift erst, wenn ein Land gesetzt ist. |

---

## 5. Offene Fragen

| Frage | Grund, warum nicht verifiziert |
|---|---|
| Sind `packlink-pro-shipping`, `woocommerce-services`, `woocommerce-payments` (Subscriptions) und `woocommerce-paypal-payments` in dieser Instanz aktiv? | Erfordert Lesen von `wp_options.active_plugins` bzw. WP-CLI; im Rahmen dieser Stufe nicht durchgefuehrt (nur Dateisystem geprueft). |
| Welche Callbacks haengen zur Laufzeit tatsaechlich an `woocommerce_package_rates` und `woocommerce_shipping_packages`? | Statisch nur Kern-Callbacks belegbar (`ShippingController.php:80, 83`). Fremdcode-Callbacks sind nur zur Laufzeit sichtbar. Gehoert in Stufe 3. |
| Verhalten des Block-Frontends bei leerer Ratenliste | Nur minifizierte Bundles im Repo (`assets/client/blocks/cart-frontend.js`). Kein Quelltext, daher nicht belegbar. |
| Ist `woocommerce_shipping_debug_mode` in dieser Installation gesetzt? | Optionswert nicht gelesen. |
| Fehlerkatalog Fall 2 "Loeschen einer Versandklasse, die noch zugewiesen ist" | Es wurde keine Stelle gefunden, die beim Loeschen des Terms `product_shipping_class` die Produktzuweisungen prueft. Ob WordPress-Term-Loeschung die Zuweisung automatisch entfernt, wurde nicht verifiziert. |
| Reihenfolge der Preisberechnung gegenueber `contents_cost` im Paket | `get_shipping_packages()` nutzt `line_total` (`class-wc-cart.php:1674`). Ob dieser Wert zum Zeitpunkt des Aufrufs immer bereits rabattiert vorliegt, wurde nicht durchgerechnet. Relevant fuer Fehlerkatalog Fall 20. |
| V4-REST-Routen `ShippingZones` und `ShippingZoneMethod` | Nur die Cache-Invalidierung geprueft (`ShippingZoneMethodService.php:176`), nicht die Routenregistrierung und Berechtigungen. |
| Agentic-Checkout-Routen (`src/StoreApi/Routes/V1/Agentic`, `AgenticCheckoutUtils.php:365-390`) | Nur oberflaechlich gesichtet; eigener Pfad zur Versandpruefung, Einordnung in die Domaene noch offen. |
| `WC_Cart_Session` Zeilen 415-430 | Der zweite Block, der die Versand-Sessionschluessel loescht, wurde gefunden, aber der ausloesende Kontext nicht vollstaendig gelesen. |
| Verhalten bei mehreren Paketen | Der Kern erzeugt genau ein Paket (`class-wc-cart.php:1670-1692`). Mehrere Pakete entstehen nur durch Fremdcode ueber `woocommerce_cart_shipping_packages`. Wie robust die Vorauswahl-Logik dann ist, wurde nicht geprueft. |

---

## 6. Quellen

Geprueft und zitiert:

```
plugins/woocommerce/includes/class-wc-cart.php
plugins/woocommerce/includes/class-wc-cart-totals.php
plugins/woocommerce/includes/class-wc-cart-session.php
plugins/woocommerce/includes/class-wc-cart-fees.php
plugins/woocommerce/includes/class-wc-shipping.php
plugins/woocommerce/includes/class-wc-shipping-rate.php
plugins/woocommerce/includes/class-wc-shipping-zone.php
plugins/woocommerce/includes/class-wc-shipping-zones.php
plugins/woocommerce/includes/class-wc-customer.php
plugins/woocommerce/includes/class-wc-countries.php
plugins/woocommerce/includes/class-wc-tax.php
plugins/woocommerce/includes/class-wc-ajax.php
plugins/woocommerce/includes/class-wc-form-handler.php
plugins/woocommerce/includes/class-woocommerce.php
plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php
plugins/woocommerce/includes/data-stores/class-wc-shipping-zone-data-store.php
plugins/woocommerce/includes/shipping/flat-rate/class-wc-shipping-flat-rate.php
plugins/woocommerce/includes/shipping/free-shipping/class-wc-shipping-free-shipping.php
plugins/woocommerce/includes/shipping/local-pickup/class-wc-shipping-local-pickup.php
plugins/woocommerce/includes/legacy/class-wc-legacy-shipping-zone.php
plugins/woocommerce/includes/legacy/class-wc-legacy-customer.php
plugins/woocommerce/includes/shortcodes/class-wc-shortcode-cart.php
plugins/woocommerce/includes/widgets/class-wc-widget-cart.php
plugins/woocommerce/includes/wc-cart-functions.php
plugins/woocommerce/includes/wc-core-functions.php
plugins/woocommerce/includes/wc-conditional-functions.php
plugins/woocommerce/includes/wc-formatting-functions.php
plugins/woocommerce/includes/wc-order-functions.php
plugins/woocommerce/includes/wc-template-functions.php
plugins/woocommerce/includes/admin/class-wc-admin-notices.php
plugins/woocommerce/includes/admin/settings/class-wc-settings-shipping.php
plugins/woocommerce/includes/admin/settings/class-wc-settings-tax.php
plugins/woocommerce/includes/admin/views/html-notice-no-shipping-methods.php
plugins/woocommerce/src/Utilities/ShippingUtil.php
plugins/woocommerce/src/StoreApi/Utilities/CartController.php
plugins/woocommerce/src/StoreApi/Utilities/LocalPickupUtils.php
plugins/woocommerce/src/StoreApi/Utilities/AgenticCheckoutUtils.php
plugins/woocommerce/src/StoreApi/Routes/V1/CartSelectShippingRate.php
plugins/woocommerce/src/StoreApi/Routes/V1/CartUpdateCustomer.php
plugins/woocommerce/src/StoreApi/Schemas/V1/CartSchema.php
plugins/woocommerce/src/StoreApi/Schemas/V1/CartShippingRateSchema.php
plugins/woocommerce/src/Blocks/Shipping/ShippingController.php
plugins/woocommerce/src/Blocks/Shipping/PickupLocation.php
plugins/woocommerce/src/Blocks/Utils/CartCheckoutUtils.php
plugins/woocommerce/src/Internal/RestApi/Routes/V4/ShippingZoneMethod/ShippingZoneMethodService.php
plugins/woocommerce/templates/cart/cart-shipping.php
plugins/woocommerce/templates/cart/shipping-calculator.php
plugins/woocommerce/templates/cart/cart-totals.php
plugins/woocommerce/assets/js/frontend/cart.js
```

Verzeichnisse gelistet (ohne Einzeldateipruefung):
`plugins/woocommerce/src/StoreApi/Routes/V1/`,
`plugins/woocommerce/src/StoreApi/Schemas/V1/`,
`plugins/woocommerce/src/Utilities/`,
`plugins/woocommerce/includes/shipping/`.

Projektdokumente:
`CLAUDE.md`, `VORGEHEN.md`,
`.gemini/prompts/domain-map-prompt.md`,
`analysis/_domain/cart/error-catalog.md`,
`analysis/_domain/cart/full-analysis.md`,
`analysis/plugins/woocommerce/includes/wc-cart.md`, `wc-cart-totals.md`,
`wc-cart-session.md`, `wc-cart-fees.md`, `wc-cart-functions.md`,
`analysis/plugins/woocommerce/includes/legacy/wc-legacy-cart.md`,
`analysis/plugins/woocommerce/includes/shortcodes/wc-shortcode-cart.md`,
`analysis/plugins/woocommerce/includes/widgets/wc-widget-cart.md`.
