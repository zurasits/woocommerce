# Analyse: class-wc-widget-cart.php

## 1. Klassen-Übersicht

`WC_Widget_Cart` ist ein klassisches WordPress-Widget, das einen Platzhalter für den "Mini-Cart" rendert. Der eigentliche Inhalt (Artikel, Summe, Buttons) wird nicht serverseitig gerendert, sondern per JavaScript (`wc-cart-fragments`) nachträglich in den leeren `<div>` eingefügt.

- **Elternklasse:** `WC_Widget` (Basisklasse für alle WooCommerce-Widgets, stellt `widget_start()`/`widget_end()` und das Settings-Framework bereit).
- **Wichtigste Abhängigkeiten:** `wp_enqueue_script( 'wc-cart-fragments' )`, WordPress-Widget-API (`WP_Widget` via `WC_Widget`), `is_cart()`/`is_checkout()`/`is_customize_preview()`.

## 2. Public Methoden nach Kategorie

| Methode | Zweck |
|---|---|
| `__construct()` | Definiert Widget-Metadaten (Titel, Beschreibung, Settings-Schema) |
| `widget( $args, $instance )` | Rendert den Platzhalter-Container für das per JS befüllte Mini-Cart |

## 3. Auffälligkeiten & Probleme

1. **Zeile 39-41 und Zeile 59**: `wp_enqueue_script( 'wc-cart-fragments' )` wird sowohl im Konstruktor (nur im Customizer-Preview-Fall) als auch unbedingt in `widget()` aufgerufen. Funktional unschädlich, da `wp_enqueue_script()` idempotent ist, aber die doppelte Stelle ist redundant und könnte den Eindruck erwecken, unterschiedliche Bedingungen zu erfüllen.
2. **Zeile 74** (`widget()`): Der gesamte sichtbare Inhalt ist ein leerer `<div class="widget_shopping_cart_content">`. Ohne funktionierendes JavaScript (blockiert, Ladefehler, deaktiviert) bleibt das Widget dauerhaft leer – es gibt keinen serverseitig gerenderten Fallback-Inhalt (progressive enhancement fehlt).
3. **Zeile 61-78**: Die "hide if empty"-Logik erzeugt nur eine CSS-Klasse (`hide_cart_widget_if_empty`); ob der Warenkorb tatsächlich leer ist, entscheidet clientseitiges JS/CSS, nicht diese PHP-Methode. Das ist by design (Fragment-Cache-Kompatibilität), aber die serverseitige Ausgabe suggeriert auf den ersten Blick eine PHP-seitige Entscheidung, die real nicht getroffen wird.

## 4. Verbesserungsvorschläge

**Niedrig**
- Redundanten `wp_enqueue_script()`-Aufruf im Konstruktor (Zeile 40) entfernen, da `widget()` das Skript ohnehin immer einbindet.
- Kurzen Kommentar ergänzen, der erklärt, dass der leere Platzhalter beabsichtigt ist (Fragment-Cache-Strategie), um Verwirrung bei künftigen Bearbeitern zu vermeiden.

## 5. Bewertung

| Kriterium | Bewertung |
|---|---|
| Code-Qualität | 8/10 |
| Testbarkeit | 7/10 |
| Wartbarkeit | 8/10 |
