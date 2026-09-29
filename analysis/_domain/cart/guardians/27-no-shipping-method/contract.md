# Adapter-Vertrag: Waechter 27 — Keine Versandmethode verfuegbar

**Domaene:** Warenkorb
**Fall:** 27 aus `analysis/_domain/cart/error-catalog.md`
**Stufe:** 5, Schritt 2 nach VORGEHEN.md
**Pruefebene:** 1 (Konfiguration, ohne Warenkorb)
**Stand:** Entwurf, kein Code vorhanden

---

## 1. Zweck

Der Waechter prueft, ob ein Shop, der Versand anbietet, technisch
ueberhaupt in der Lage ist, Versandkosten zu liefern. Geprueft wird
ausschliesslich die Konfiguration, nicht der Zustand eines konkreten
Warenkorbs oder Kunden.

Der Waechter meldet. Er blockiert nichts.

---

## 2. Neutrale Daten fuer den Regelkern

Der Regelkern erhaelt ausschliesslich primitive, shopsystem-neutrale
Werte. Er greift nicht auf Datenbank, Request oder Session zu.

| Feldname | Typ | Bedeutung | Quelle WooCommerce | Beleg |
|---|---|---|---|---|
| `shipping_globally_enabled` | bool | Hauptschalter Versand | `wc_shipping_enabled()` | `includes/wc-conditional-functions.php:408` |
| `ships_to_any_region` | bool | Shop liefert laut Einstellung ueberhaupt irgendwohin | Option `woocommerce_ship_to_countries`, bei Wert `specific` zusaetzlich `woocommerce_specific_ship_to_countries` nicht leer | Registrierung `includes/admin/settings/class-wc-settings-general.php:230`, Auswertung `includes/class-wc-countries.php:356-378` |
| `enabled_shipping_methods_count` | int | Anzahl **aktivierter** Versandmethoden ueber alle Regionen | Nicht ueber eine Zaehl-Hilfsfunktion beziehen, sondern aus den Regionen aggregieren (Fussnote 1): Summe ueber `WC_Shipping_Zones::get_zones()`, je Zone `WC_Shipping_Zone::get_shipping_methods( true )`, plus separat Zone 0 | `includes/class-wc-shipping-zones.php:26-38`, `includes/class-wc-shipping-zone.php:159`, Enabled-Flag Zeile 191 |
| `legacy_methods_active` | bool | Sind Alt-Methoden ohne Regionsbindung aktiv | `WC()->shipping()->get_shipping_methods()`, Methode mit `enabled === 'yes'` und `! supports( 'shipping-zones' )` | Kriterium wie `includes/wc-core-functions.php:1670-1672` |
| `target_regions` | string[] | Regionen, in die der Shop laut Einstellung liefert | `WC_Countries::get_shipping_countries()` | `includes/class-wc-countries.php:356-380` |
| `covered_regions` | string[] | Regionen, fuer die mindestens eine aktivierte Methode existiert | `WC_Shipping_Zones::get_zones()`, je Zone `get_zone_locations()` + `get_shipping_methods( true )` | `includes/class-wc-shipping-zones.php:26-38`, `includes/class-wc-shipping-zone.php:98` und `:159` |
| `partially_covered_regions` | string[] | Regionen, die nur teilweise abgedeckt sind (Ortstyp Bundesland oder PLZ) | dieselben Zonenorte, Ortstyp `state` oder `postcode` | `includes/data-stores/class-wc-shipping-zone-data-store.php:315-321` |
| `has_fallback_region` | bool | Existiert ein Auffangbereich ohne Regionsbeschraenkung mit aktivierter Methode | Zone 0, **separat** ueber `WC_Shipping_Zones::get_zone( 0 )` (Fussnote 2) | `includes/class-wc-shipping-zones.php:78` |

Begriffsklaerung: "Region" ist bewusst neutral. In WooCommerce ist es
Zone plus Land/Bundesland/PLZ, in Shopware eine Regel, in Shopify eine
Zone. Der Kern kennt nur eine Zeichenkette.

Normalisierungsebene ist das **Land** (ISO-2). Ein Zonenort vom Typ
`continent` wird vom Adapter auf seine Laender aufgeloest. Ein Ort vom Typ
`state` oder `postcode` zaehlt das Land als abgedeckt und wird zusaetzlich in
`partially_covered_regions` gefuehrt. Die Bewertung der Teilabdeckung ist ein
eigener Fall, nicht Fall 27; das Feld wird jetzt schon mitgeliefert, damit der
Kern spaeter ohne Vertragsaenderung darauf zugreifen kann.

### Fussnote 1 - Warum nicht `wc_get_shipping_method_count()`

Die naheliegende Hilfsfunktion ist fuer diesen Waechter **unbrauchbar**.
Ihre Signatur lautet
`wc_get_shipping_method_count( $include_legacy = false, $enabled_only = false )`
(`includes/wc-core-functions.php:1650`). Ein einzelnes `true` als erstes
Argument setzt also `include_legacy`, nicht `enabled_only`. Der Rueckgabewert
ist dann `enabled + disabled + legacy`
(`includes/wc-core-functions.php:1694-1706`).

Ein Shop, dessen Regionsmethoden saemtlich deaktiviert sind, haette damit
einen Zaehlerstand groesser 0. Der Befund `NO_METHODS_ENABLED` wuerde nicht
feuern - genau in dem Zustand, der laut Abschnitt 4 den Mehrwert dieses
Waechters ausmacht.

Zweiter Grund: die Funktion liest aus dem Transient
`wc_shipping_method_count` mit 30 Tagen Haltbarkeit
(`includes/wc-core-functions.php:1653-1656` und `:1687`). Ein Waechter, der
die Konfiguration auf Korrektheit prueft, darf sich nicht auf einen Cache
stuetzen, der selbst veralten kann.

Aktive Alt-Methoden gehen bewusst **nicht** in
`enabled_shipping_methods_count` ein, sondern in `legacy_methods_active`.
Sonst maskiert eine einzige aktive Alt-Methode den Befund "keine
Regionsmethode aktiv".

### Fussnote 2 - Der Auffangbereich fehlt in der Zonenliste

`WC_Shipping_Zones::get_zones()` liefert Zone 0 **nicht** mit. Der Data-Store
liest ausschliesslich die Tabelle `woocommerce_shipping_zones`
(`includes/data-stores/class-wc-shipping-zone-data-store.php:358-361`), und
Zone 0 steht dort nicht drin - sie ist virtuell.

`has_fallback_region` muss deshalb ueber einen separaten Aufruf
`WC_Shipping_Zones::get_zone( 0 )` gebildet werden. Wird das uebersehen, ist
das Feld dauerhaft false und `NO_FALLBACK_REGION` meldet permanent einen
Fehlalarm.

### Fussnote 3 - Quelle mit Seiteneffekt, im Adapter verboten

`wc_get_chosen_shipping_method_for_package()` schreibt in die Session und
feuert eine Action. Die Funktion darf in keinem Adapter dieses Waechters
verwendet werden, weil sie den Trockenlauf (Abschnitt 7) verletzt. Fuer
Pruefebene 1 wird sie ohnehin nicht gebraucht; der Hinweis gilt vorsorglich
fuer den spaeteren Laufzeit-Waechter.

---

## 3. Befunde des Kerns

| Befund | Schweregrad | Bedingung |
|---|---|---|
| `NO_METHODS_ENABLED` | CRITICAL | `enabled_shipping_methods_count` = 0 |
| `ONLY_LEGACY_METHODS_ACTIVE` | WARNING | `enabled_shipping_methods_count` = 0 UND `legacy_methods_active` true |
| `REGIONS_UNCOVERED` | CRITICAL | `target_regions` minus `covered_regions` ist nicht leer UND `has_fallback_region` false |
| `NO_FALLBACK_REGION` | WARNING | `has_fallback_region` false, alle uebrigen Pruefungen ohne Befund |

Kein Befund bei `shipping_globally_enabled` = false oder
`ships_to_any_region` = false. Das ist ein gewollter Zustand, etwa bei
reinen Download-Shops. Der Kern bricht in diesem Fall vor allen weiteren
Pruefungen ab und liefert eine leere Befundliste.

`NO_METHODS_ENABLED` zaehlt ausschliesslich aktivierte **Regionsmethoden**.
Sind daneben Alt-Methoden ohne Regionsbindung aktiv, kommt
`ONLY_LEGACY_METHODS_ACTIVE` als zweiter Befund hinzu: der Versand
funktioniert dann zwar moeglicherweise, aber an der Regionslogik vorbei und
damit unkontrolliert. Der kritische Befund bleibt bestehen.

Bei `REGIONS_UNCOVERED` gehoert die Liste der nicht abgedeckten
Regionen in den Befund. Ohne sie ist die Meldung fuer den Betreiber
nutzlos.

---

## 4. Abgrenzung zur bestehenden Kernwarnung

WooCommerce meldet in
`WC_Admin_Notices::no_shipping_methods_notice()`
(`includes/admin/class-wc-admin-notices.php:482-496`) bereits den Fall
"keine Versandmethode angelegt".

Der Waechter geht darueber hinaus:

| Zustand | Kernwarnung | Waechter 27 |
|---|---|---|
| Keine Methode angelegt | ja | ja |
| Methoden angelegt, aber alle deaktiviert | **nein** | ja |
| Lieferland ohne passende Zone | nein | ja |
| Kein Auffangbereich vorhanden | nein | ja (Warnung) |

Beleg fuer Zeile 2: die Kernwarnung ruft in
`includes/admin/class-wc-admin-notices.php:486`
`wc_get_shipping_method_count()` **ohne Argumente** auf. Damit gilt
`enabled_only = false`, gezaehlt wird `enabled + disabled`
(`includes/wc-core-functions.php:1694-1700`). Die Warnung erscheint nur bei
`0 === $method_count` (Zeile 488). Ein Shop mit angelegten, aber saemtlich
deaktivierten Methoden liegt darueber und bleibt unbemerkt.

Zwei weitere Einschraenkungen der Kernwarnung: sie erscheint nur, wenn
mindestens ein veroeffentlichtes Produkt existiert (Zeile 485, 488), und sie
wird auf der Versand-Einstellungsseite selbst unterdrueckt (Zeile 484) -
also genau dort, wo der Betreiber die Konfiguration bearbeitet.

Der eigentliche Mehrwert liegt in den Zeilen 2 bis 4. Diese Faelle bleiben
in WooCommerce unbemerkt, bis ein Kunde abbricht.

---

## 5. Ausdrueckliche Abgrenzung

Nicht geprueft wird:

- Ob fuer die konkrete Adresse eines Kunden eine Rate herauskommt.
  Laufzeit, Pruefebene 3, eigener Waechter.
- Ob ueberlappende Zonen Methoden verschlucken. Fall 26.
- Ob der Warenkorb ueberhaupt versandpflichtige Positionen enthaelt.
  Fall 29.
- Ob die Kundenadresse vollstaendig ist. Laufzeit.
- Ob Schwellen wie Mindestbestellwert erreicht sind. Laufzeit.
- Ob Fremdcode ueber Filter Raten entfernt. Erst nach Stufe 3
  (Hook-Index) beurteilbar.

Die vier Gruende, aus denen die Versandberechnung zur Laufzeit gar nicht
erst startet - Versand global abgeschaltet, keine Versandmethode angelegt,
leerer Warenkorb, unvollstaendige Kundenadresse - gehoeren zum
Laufzeit-Waechter, nicht hierher. Systembezug, Codestellen und Belege dazu
stehen in `analysis/_domain/cart/flow-shipping-calculation.md`,
Abschnitt 9. Sie bleiben Vorarbeit fuer den zweiten Waechter.

---

## 6. Nachweis der Shopsystem-Unabhaengigkeit

Kein Feldname und keine Befundbedingung enthaelt einen
WooCommerce-Begriff: weder Zone noch Package noch einen Klassennamen oder
ein `wc_`-Praefix. Systembezug steht ausschliesslich in der Adapter-Spalte
von Abschnitt 2, in den Fussnoten und in den Abschnitten 4, 6 und 9.

Jedes Shopsystem mit physischem Versand kennt die drei Konzepte:

| Konzept | WooCommerce | Shopware 6 | Shopify |
|---|---|---|---|
| Hauptschalter Versand | `wc_shipping_enabled()` | Versandarten aktiv | Shipping aktiv |
| Aktivierte Versandmethoden | Zonenmethoden | Versandarten | Shipping rates |
| Regionsabdeckung | Zonen + Laender | Regeln + Laender | Zonen |

Der Regelkern bleibt bei einem Wechsel unveraendert. Nur der Adapter
wird ersetzt.

---

## 7. Trockenlauf

Eingabe ist ein Wertobjekt aus den acht Feldern in Abschnitt 2.
Ausgabe ist eine Liste von Befunden.

Der Kern liest nichts, schreibt nichts, feuert nichts. Damit ist
derselbe Kern spaeter ohne Aenderung fuer Massenimport-Pruefung und
Cron-Laeufe verwendbar.

Daraus folgt eine Auflage an jeden Adapter: er darf keine Quelle benutzen,
die beim Lesen etwas veraendert. Ein konkreter Fall dazu steht in
Fussnote 3.

---

## 8. Offene Fragen

Geklaert:

- ~~Zeilennummern der mit TODO markierten Quellen.~~ Eingetragen in
  Abschnitt 2.
- ~~Meldet die Kernwarnung auch den Fall "Methoden vorhanden, alle
  deaktiviert"?~~ Nein. Beleg in Abschnitt 4.
- ~~Wie werden Regionen normalisiert, wenn eine Zone auf PLZ-Ebene
  eingeschraenkt ist?~~ Normalisierung auf Landesebene, Land zaehlt als
  abgedeckt, zusaetzlich Ausweis in `partially_covered_regions`. Der
  Vorschlag des Betreibers wurde uebernommen. Die Bewertung der
  Teilabdeckung ist als eigener Fall in den Fehlerkatalog aufgenommen.
- ~~Auf welcher Basis wird `covered_regions` gebildet, wenn eine Zone
  Kontinente oder Bundesstaaten enthaelt?~~ Der Kern von WooCommerce kennt
  vier Ortstypen: `country`, `state`, `continent`, `postcode`
  (`includes/data-stores/class-wc-shipping-zone-data-store.php:315-321`).
  Der Adapter loest `continent` auf Laender auf, `state` und `postcode`
  zaehlen das Land.

Weiterhin offen:

- Aufloesung von `continent` auf Laender: `WC_Countries` liefert
  `get_continent_code_for_country()` fuer die Gegenrichtung
  (`includes/data-stores/class-wc-shipping-zone-data-store.php:310`). Ob es
  einen ebenso stabilen Weg von Kontinent zu Laenderliste gibt, ist beim
  Bau des Adapters zu pruefen.
- Fehlalarm-Toleranz: `NO_FALLBACK_REGION` ist bewusst nur WARNING. Ob ein
  Shop mit vollstaendiger Laenderabdeckung den Auffangbereich ueberhaupt
  braucht, ist eine Betreiberentscheidung. Gegebenenfalls abschaltbar machen.

---

## 9. Quellen

- `includes/wc-conditional-functions.php`
- `includes/wc-core-functions.php`
- `includes/class-wc-countries.php`
- `includes/admin/class-wc-admin-notices.php`
- `includes/admin/settings/class-wc-settings-general.php`
- `includes/class-wc-shipping-zones.php`
- `includes/class-wc-shipping-zone.php`
- `includes/data-stores/class-wc-shipping-zone-data-store.php`
- `analysis/_domain/cart/domain-map.md`
- `analysis/_domain/cart/flow-shipping-calculation.md`
- `analysis/_domain/cart/error-catalog.md`