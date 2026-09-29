# Hook-Index: Warenkorb

| Feld | Wert |
|---|---|
| Domäne | Warenkorb |
| Stufe | 3 (Hook-Index nach VORGEHEN.md) |
| Datum | 2026-08-11 |
| Stand | Erfasst die Hooks der Warenkorb-Engine und Versandberechnung nach Stufe 3 Vorgaben. |

Dieser Hook-Index konzentriert sich auf Actions und Filter, die den Ablauf im Cart (insbesondere die Kalkulation und den Versand) steuern. Templates-Hooks (Anzeige) sind nur rudimentär erfasst, sofern sie eine Warnung im Frontend maskieren könnten.

---

## 1. Generelle Cart-Hooks (Berechnung & Items)

| Hook-Name | Typ | Ausgelöst in | Zeitpunkt | Parameter | Kern-Callbacks | Risiko |
|---|---|---|---|---|---|---|
| `woocommerce_before_calculate_totals` | Action | `plugins/woocommerce/includes/class-wc-cart.php:1541` | Vor dem Instanziieren von `WC_Cart_Totals` am Start der Summierung. | `$this` (`WC_Cart`) | keine ersichtlich | **Extrem hoch.** Beliebter Hook für Preismanipulationen. Werden Artikelpreise hier gesetzt, ändern sie die gesamte Rechnung. Bei fehlerhaften Plugins droht Endlosschleife, wenn hierin fälschlich `$cart->calculate_totals()` aufgerufen wird. |
| `woocommerce_after_calculate_totals` | Action | `plugins/woocommerce/includes/class-wc-cart.php:1545` | Nach Abschluss der Summenkalkulation. | `$this` (`WC_Cart`) | keine ersichtlich | **Niedrig.** Änderungen am Cart-Objekt fließen hier nicht mehr in die Totals ein. Dient eher Logging und Caching-Trigger. |
| `woocommerce_cart_calculate_fees` | Action | `plugins/woocommerce/includes/class-wc-cart.php:2206` | Wenn die Totals-Engine die Zusatzgebühren anfordert. | `$this` (`WC_Cart`) | keine ersichtlich | **Mittel.** Fremdcode kann beliebige Gebühren mit `$cart->add_fee()` hinzufügen. Wenn Steuerklassen fehlen, werden Steuern nicht sauber mitberechnet. |
| `woocommerce_add_cart_item_data` | Filter | `plugins/woocommerce/includes/class-wc-cart.php:1267` | Beim Aufbau des Arrays für ein neues Item. | `$cart_item_data`, `$product_id`, `$variation_id`, `$quantity` | keine ersichtlich | **Mittel.** Ändert die Metadaten. Fehlerhafter Fremdcode kann Keys überschreiben oder durch Array-Veränderungen den Item-Hash verfälschen, wodurch Items im Warenkorb nicht gruppiert werden. |
| `woocommerce_add_to_cart_validation` | Filter | `plugins/woocommerce/includes/class-wc-ajax.php:520` | Vor dem Einfügen eines Produkts (AJAX, POST, Session). | `$passed_validation`, `$product_id`, `$quantity`, ... | `wc_protected_product_add_to_cart` (`wc-cart-functions.php:33`) | **Hoch.** Filter auf `false` blockiert den "In den Warenkorb"-Vorgang vollständig. Oft Quelle für unerklärliche Blockaden ohne sichtbare Fehlermeldung, wenn der Callback keine Notice setzt. |

## 2. Versandbezogene Hooks (Ermittlung & Pakete)

| Hook-Name | Typ | Ausgelöst in | Zeitpunkt | Parameter | Kern-Callbacks | Risiko |
|---|---|---|---|---|---|---|
| `woocommerce_cart_shipping_packages` | Filter | `plugins/woocommerce/includes/class-wc-cart.php:1669` | Nach Sammlung versandpflichtiger Items, vor Ratenberechnung. | `$packages` | keine ersichtlich | **Hoch.** Paketaufteilung und Zieladresse können frei überschrieben werden. Setzt Fremdcode die Adresse zurück, scheitert die Zonenfindung im nächsten Schritt. |
| `woocommerce_shipping_packages` | Filter | `plugins/woocommerce/includes/class-wc-shipping.php:278` | Nach Iteration der Pakete und Ermittlung aller Raten, vor dem Speichern in der Session. | `$packages` | `ShippingController.php:80, 83` (zweifach) | **Hoch.** Fremdcode kann Raten löschen oder Pakete zerstören. Durch die Prioritätskonflikte mit den Kern-Hooks von WooCommerce unvorhersehbar (siehe domain-map H9). |
| `woocommerce_package_rates` | Filter | `plugins/woocommerce/includes/class-wc-shipping.php:398` | Nachdem alle Methoden eines Pakets Raten geliefert haben. | `$rates`, `$package` | keine ersichtlich | **Extrem hoch.** Kern-Hook für Preismanipulation von Versandraten durch Plugins. Gibt ein Plugin hier `null` zurück statt des Arrays, verschwinden alle Raten wortlos. |
| `woocommerce_shipping_methods` | Filter | `plugins/woocommerce/includes/class-wc-shipping.php:152` | Registrierung zusätzlicher Methodenklassen beim Init. | `$methods` | Core-Registrierung | **Mittel.** Falsch konfigurierte Arrays können Core-Klassen (z.B. Flat Rate) abhängen. |
| `woocommerce_load_shipping_methods` | Action | `plugins/woocommerce/includes/class-wc-shipping.php:185` | Bei der Initialisierung der Methoden je Paket. | `$package` | keine ersichtlich | **Gering.** Dient manueller Instanziierung, meist Legacy-Verhalten. |
| `woocommerce_shipping_zone_shipping_methods` | Filter | `plugins/woocommerce/includes/class-wc-shipping-zone.php:208` | Beim Laden der konfigurierten Methoden für eine Zone. | `$methods`, `$raw_methods`, `$allowed_classes` | keine ersichtlich | **Mittel.** Kann konfigurierte Methoden aus der laufenden Ermittlung werfen (z.B. basierend auf Wochentagen), was für Support unerklärlich ist, weil das Backend anders aussieht. |
| `woocommerce_get_zone_criteria` | Filter | `plugins/woocommerce/includes/data-stores/class-wc-shipping-zone-data-store.php:341` | SQL-Generierung zur Zonen-Kollisionsprüfung (Adresse vs. Zone). | `$criteria`, `$package`, `$postcode` | keine ersichtlich | **Hoch.** Fremdcode kann das WHERE-Statement erweitern und so Zonen erzwingen oder ignorieren. Führt zu schwer debuggbaren SQL-Missmatches (Fall 26 verwandt). |
| `woocommerce_shipping_<id>_is_available` | Filter | `plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php:456` | Kurz vor Ausführung der Berechnung für eine spezifische Methode. | `$is_available`, `$package` | keine ersichtlich | **Hoch.** Beliebiges An- und Abschalten von Raten je nach Drittanbieter-Bedingung, ungeachtet der Backend-Zonen-Logik. |
| `woocommerce_shipping_method_add_rate` / `_add_rate_args` | Filter | `plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php:376, 292` | Vor dem Einfügen einer einzelnen Rate durch die Methode. | `$args` (Raten-Daten) | keine ersichtlich | **Mittel.** Preis- und Steuer-Array einer bestimmten Rate wird verbogen. Verursacht fehlerhafte Steuerbasen. |
| `woocommerce_shipping_chosen_method` | Filter | `plugins/woocommerce/includes/wc-cart-functions.php:532, 574` | Bei der Vorauswahl (erste Rate). | `$default`, `$rates`, `$chosen_method` | keine ersichtlich | **Mittel.** Verbiegt, was der Kunde als Default-Methode vorausgewählt sieht. |
| `woocommerce_shipping_method_chosen` | Action | `plugins/woocommerce/includes/wc-cart-functions.php:483` | Nachdem eine Rate in der Session als gewählt hinterlegt wurde. | `$method` | keine ersichtlich | **Gering.** Dient meist dem Validieren oder Flushen von Fremdcaches. |
| `woocommerce_cart_needs_shipping` | Filter | `plugins/woocommerce/includes/class-wc-cart.php:1778` | Grundsatzentscheidung: Braucht der Cart Versand? | `$needs_shipping` | keine ersichtlich | **Hoch.** Filtert Fremdcode hier fälschlich `false`, gelten auch physische Waren als digital. Der Versandbalken verschwindet und die Checkout-Validierung fällt aus (Fall 29). |
| `woocommerce_cart_ready_to_calc_shipping` | Filter | `plugins/woocommerce/includes/class-wc-cart.php:1809, 1866` | Innerhalb von `show_shipping()`: Soll gerechnet werden? | `$show_shipping` | keine ersichtlich | **Hoch.** Steuert, ob Raten überhaupt ermittelt werden. Falsche Manipulation führt wie Fall 27 zu stummen Blockaden. |
| `woocommerce_shipping_prices_include_tax` | Filter | `plugins/woocommerce/includes/class-wc-tax.php:93` | Beim Ermitteln der steuerlichen Basis der Raten. | `$inc_tax` | keine ersichtlich | **Hoch.** Überschreibt die globale Einstellung. Produziert Rundungsfehler und Cent-Abweichungen (Fall 19 verwandt). |
| `woocommerce_shipping_tax_class` | Filter | `plugins/woocommerce/includes/class-wc-tax.php:619` | Bestimmung der Steuerklasse (Standard, Ermäßigt, Erben). | `$tax_class`, `$cart` | keine ersichtlich | **Mittel.** Verursacht falsch berechnete Versandsteuern, wenn `inherit` von Fremdcode falsch überschrieben wird. |
| `woocommerce_customer_taxable_address` | Filter | `plugins/woocommerce/includes/class-wc-customer.php:222` | Ermittlung der Adresse für die Steuerbasis. | `$address` | `ShippingController.php:78` | **Hoch.** Ersetzt die Steuerbasis. Konflikte mit WooCommerce Kern-Hook (Block-basiertes Checkout-Design überschreibt oft diese Adresse) führen zu falschen Steuersätzen. (Fall 24). |
| `woocommerce_apply_base_tax_for_local_pickup` | Filter | `plugins/woocommerce/includes/class-wc-customer.php:191` | Umschalten auf die Shop-Adresse als Steuerbasis bei Abholung. | `$apply` | keine ersichtlich | **Gering.** |

## 3. UI-Hooks & Fallbacks (Anzeige)

| Hook-Name | Typ | Ausgelöst in | Zeitpunkt | Parameter | Kern-Callbacks | Risiko |
|---|---|---|---|---|---|---|
| `woocommerce_calculated_shipping` | Action | `plugins/woocommerce/includes/shortcodes/class-wc-shortcode-cart.php:59` | Nach Absenden des Cart-Rechners. | keine | keine ersichtlich | **Gering.** |
| `woocommerce_store_api_cart_select_shipping_rate` | Action | `plugins/woocommerce/src/StoreApi/Routes/V1/CartSelectShippingRate.php:108` | Nach Methoden-Auswahl im Store API. | `$request` | keine ersichtlich | **Gering.** |
| `woocommerce_cart_no_shipping_available_html` (und ähnliche) | Filter | `plugins/woocommerce/templates/cart/cart-shipping.php:62, 64, 67, 79` | Fallback-Texte bei nicht vorhandenen Raten. | `$html` | keine ersichtlich | **Gering.** Reines Anzeige-Risiko. Kann allerdings benutzt werden, um Konfigurationswarnungen für Kunden zu "übersetzen" und den Fehler zu kaschieren. |

---

## 4. Offene Fragen

*   **Laufzeit-Belegung:** Welche Callbacks von häufigen Fremd-Plugins (z. B. `packlink-pro-shipping`, Subscriptions) hängen sich hier de facto ein? Dieser Hook-Index zeigt die Einstiegspunkte des Kerns, die tatsächliche Belegung durch Plugins muss im laufenden System ausgelesen werden.
*   **Action Scheduler:** Einige Drittanbieter verlegen Versandaktionen (wie Drop-Shipping Anmeldung) über Hook `woocommerce_after_calculate_totals` in den asynchronen Action Scheduler. Die Abgrenzung dieser Effekte zur reinen Cart-Domäne bedarf genauerer Untersuchung.
