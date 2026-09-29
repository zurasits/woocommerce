# VORGEHEN.md

Konkrete Arbeitsweise für die domänenorientierte Erschließung eines Systems.
Gilt pro Domäne. Reihenfolge einhalten — jede Stufe baut auf der vorherigen auf.

## Unumstoessliche Grundregel

Diese Arbeitsweise dient dem Aufbau eines **shopsystem-unabhaengigen**
Produkts. Analyse-Artefakte beschreiben WooCommerce, aber die daraus
abgeleitete Fachlogik darf WooCommerce nicht voraussetzen.

Dreiteilung jedes Waechters: Regelkern (nicht austauschbar),
Adapter (austauschbar), Ausloeser (austauschbar).
Der Regelkern kennt keine shopsystem-spezifischen Begriffe.

Verbindliche Fassung: `CLAUDE.md`, Abschnitt
"Shop-Unabhaengigkeit (Pflichtvorgabe)".

## Namenskonvention

Ordner- und Dateinamen grundsaetzlich **englisch**, kleingeschrieben,
mit Bindestrichen. Der **Inhalt** der Dateien bleibt **deutsch**.

Domaenenordner: `cart`, `order`, `payment`, `product`, `customer`.

---

## Die vier Ebenen

| Ebene | Artefakt | Ablage | Zweck |
|---|---|---|---|
| 1 | Domänen-Karte | `analysis/_domain/<domain>/domain-map.md` | Überblick: was gehört dazu |
| 2 | Ablauf-Dokumente | `analysis/_domain/<domain>/flow-<usecase>.md` | Problem orten |
| 3 | Hook-Index | `analysis/_domain/<domain>/hook-index.md` | Fremdeingriffe finden |
| 4 | Datei-Analysen | `analysis/<gespiegelter-pfad>.md` | Nachschlagewerk |

Beispiele:
`analysis/_domain/cart/domain-map.md`
`analysis/_domain/cart/flow-shipping-calculation.md`
`analysis/_domain/cart/hook-index.md`

Der Fehlerkatalog einer Domäne liegt unter
`analysis/_domain/<domain>/error-catalog.md`.

Ebene 4 kann jederzeit parallel entstehen, ersetzt aber die Ebenen 1–3 nicht.

---

## Stufe 0 — Domäne festlegen

Vor jeder Analyse schriftlich festhalten:

- **Domänenname** (z. B. Warenkorb, Ordner `cart`)
- **Use Cases**: alle fachlichen Vorgänge der Domäne, aus Nutzersicht formuliert
- **Abgrenzung**: was gehört bewusst *nicht* dazu

Use Cases werden aus Nutzersicht formuliert, nicht aus Codesicht.
Richtig: "Produkt in den Warenkorb legen".
Falsch: "add_to_cart() aufrufen".

Faustregel: 5–12 Use Cases pro Domäne. Mehr bedeutet, die Domäne ist zu groß
geschnitten und sollte geteilt werden.

---

## Stufe 1 — Domänen-Karte

**Prompt:** `.gemini/prompts/domain-map-prompt.md`
**Ablage:** `analysis/_domain/<domain>/domain-map.md`

Ergebnis: Liste aller beteiligten Dateien, jeder Datei genau eine Schicht
zugeordnet, plus Hotspots und offene Fragen.

**Prüfschritte nach dem Durchlauf:**

1. Vergleiche mit einer reinen Namenssuche (`find . -name "*<begriff>*"`).
   Die Karte muss Dateien enthalten, die die Namenssuche verpasst hat.
   Wenn nicht, war die Analyse zu oberflächlich — nachschärfen.
2. Prüfe die Hotspots. Dort entstehen später die meisten Probleme.
3. Prüfe die Abgrenzung. Unscharfe Grenzen sind die häufigste Fehlerquelle
   bei der späteren Fehlersuche.

Erst weitergehen, wenn die Karte plausibel ist.

---

## Stufe 2 — Ablauf-Dokumente

**Ablage:** `analysis/_domain/<domain>/flow-<usecase>.md`

Nicht für alle Use Cases. Zuerst die drei bis vier wichtigsten:
die häufigsten und die fehleranfälligsten.

Pro Ablauf-Dokument:

| Abschnitt | Inhalt |
|---|---|
| Auslöser | Was startet den Vorgang (Klick, Request, Cron, Hook) |
| Ablauf | Nummerierte Schritte: Datei, Methode, Zeile, was passiert |
| Ein-/Ausgaben | Welche Daten gehen rein, welche kommen raus |
| Zustandsänderungen | Was wird in Session, DB, Cache geschrieben |
| Hooks im Ablauf | Welche Actions/Filter feuern an welcher Stelle |
| Fehlerpfade | Was passiert bei Validierungsfehlern, Exceptions |
| Typische Störquellen | Wo greift Fremdcode üblicherweise ein |
| Bezug zum Fehlerkatalog | Welcher Schritt kann welchen Fall auslösen |

Das Ablauf-Dokument ist das wichtigste Arbeitsmittel bei der Fehlersuche.
Es beantwortet die Frage "wo muss ich hinschauen" in Minuten statt Stunden.

---

## Stufe 3 — Hook-Index

**Ablage:** `analysis/_domain/<domain>/hook-index.md`

Alle Actions und Filter der Domäne erfassen:

| Spalte | Inhalt |
|---|---|
| Hook-Name | Exakter Name |
| Typ | Action oder Filter |
| Ausgelöst in | Datei + Zeile |
| Zeitpunkt | An welcher Stelle im Ablauf |
| Parameter | Was wird übergeben |
| Kern-Callbacks | Wer aus dem System selbst hängt sich ein |
| Risiko | Wie stark kann Fremdcode hier den Ablauf verändern |

Der Hook-Index ist bei WordPress/WooCommerce der schnellste Weg zur Ursache,
wenn ein Verhalten sich nicht durch den Kerncode erklären lässt.

---

## Stufe 4 — Datei-Analysen

**Prompt:** `.gemini/prompts/analyse-prompt.md`
**Ablage:** `analysis/<gespiegelter-pfad>.md`

Für jede Datei der Domänen-Karte, priorisiert:
Domänenlogik zuerst, dann Eintrittspunkte, dann Persistenz, dann Frontend.

Templates und Asset-Dateien nur bei Bedarf.

---

## Fehlersuche mit den Artefakten

Wenn ein konkretes Problem auftritt, in dieser Reihenfolge:

1. **Use Case bestimmen.** Welcher fachliche Vorgang ist betroffen?
2. **Schicht bestimmen.** Frontend, Eintritt, Logik, Persistenz?
   Meist genügt eine Frage: Ist die Anzeige falsch oder die Berechnung?
3. **Ablauf-Dokument öffnen.** Schritt für Schritt bis zur Abweichung.
4. **Hook-Index prüfen**, wenn der Kerncode das Verhalten nicht erklärt.
   Dann ist es fast immer ein Fremd-Plugin oder ein Theme.
5. **Datei-Analyse lesen** für Details der betroffenen Klasse.
6. **Erkenntnis zurückschreiben** in das jeweilige Artefakt.

Schritt 6 ist nicht optional. Ohne ihn veraltet die Dokumentation und der
Zeitvorteil verschwindet nach wenigen Wochen.

---

## Übertragung auf ein anderes System

Die Ebenen 1–4 und die Prinzipien bleiben unverändert.
Anzupassen ist nur die **Schichten-Tabelle** in
`.gemini/prompts/domain-map-prompt.md`,
weil jedes System eigene Strukturbegriffe hat.

| System | Typische Schichtbegriffe |
|---|---|
| WordPress / WooCommerce | Template, Shortcode, AJAX, StoreAPI, Hook |
| Shopware 6 | Controller, Storefront, Service, Repository, Event Subscriber |
| Laravel-basiert | Route, Controller, Service, Model, Event, Middleware |
| Symfony-basiert | Controller, Service, Entity, Repository, Event Listener |

Vorgehen bei einem neuen System:

1. Schichtbegriffe des Systems ermitteln (Doku, Ordnerstruktur)
2. `.gemini/prompts/domain-map-prompt.md` kopieren, Schichten-Tabelle ersetzen
3. Mit der kleinsten sinnvollen Domäne starten
4. Erst nach dem ersten vollständigen Durchlauf die nächste Domäne beginnen

---

## Reihenfolge der Domänen

Vom Einfachen zum Komplexen. Vorschlag für Shopsysteme:

1. `cart` (Warenkorb) — überschaubar, klare Ein- und Ausgänge
2. `product` (Produkt) — groß, aber gut abgrenzbar
3. `order` (Bestellung) — viele Zustände, viele Beteiligte
4. `payment` — viele Fremdanbindungen, am schwersten

Nicht zwei Domänen gleichzeitig bearbeiten.

---

## Bezug zum Produkt

Die Artefakte der Ebenen 1 bis 4 sind nicht Selbstzweck. Sie liefern die
Grundlage fuer die Waechter des Cart Shield (siehe CLAUDE.md, Produktziel):

| Artefakt | Liefert dem Waechter |
|---|---|
| `domain-map.md` | Welche Komponenten koennen den Fall ueberhaupt ausloesen |
| `flow-<usecase>.md` | An welcher Stelle im Ablauf geprueft werden muss |
| `hook-index.md` | Welcher Ausloeser technisch verwendet wird |
| Datei-Analyse | Woher der Adapter seine Daten holt |

Der Adapter-Vertrag eines Waechters wird aus dem Ablauf-Dokument abgeleitet,
nicht aus dem Quellcode direkt. So bleibt er portabel.

### Stufe 5 - Waechter ableiten

**Pflichtvorgabe: Shopsystem-Unabhaengigkeit.**
Jeder Waechter besteht aus drei Teilen:

| Teil | Inhalt | Austauschbar |
|---|---|---|
| Regelkern | Was ist ein Fehler, wie schwer, welche Reaktion | nein |
| Adapter | Woher die Daten kommen | ja |
| Ausloeser | Wann geprueft wird | ja |

Der Regelkern kennt keine shopsystem-spezifischen Begriffe, Klassen,
Tabellen oder Funktionsnamen. WooCommerce ist die erste Implementierung,
nicht die Zielplattform. Verbindliche Fassung dieser Vorgabe: `CLAUDE.md`,
Abschnitt "Shop-Unabhaengigkeit".

Wird ein Waechter gebaut, ohne dass diese Trennung eingehalten ist, gilt
er als nicht fertig.

**Ablage eines Waechters:**

```
analysis/_domain/<domain>/guardians/<NN>-<kurzname>/
  contract.md      Adapter-Vertrag, Pflicht, vor jeder Code-Zeile
  test-cases.md    Testfaelle, spaeter
  decisions.md     Entscheidungsprotokoll, spaeter
```

Beispiel:
`analysis/_domain/cart/guardians/27-no-shipping-method/contract.md`

**Reihenfolge.** Erst nach den Stufen 1 bis 3 einer Domaene:

1. Fall aus `error-catalog.md` waehlen.
2. **Adapter-Vertrag als `contract.md` im Waechter-Ordner anlegen**,
   bevor eine Code-Zeile entsteht. Inhalt: welche neutralen Daten der
   Regelkern braucht, welche Befunde er liefert, was er ausdruecklich
   nicht prueft.
   Der Vertrag ist in **einfacher Sprache** zu schreiben. Ein Junior ohne
   Systemkenntnis und ein Fachverantwortlicher ohne Programmierkenntnis
   muessen ihn verstehen. Technische Belege (Datei, Zeile, Funktionsname)
   gehoeren in eine eigene Spalte oder einen eigenen Abschnitt, niemals
   in den Fliesstext.
3. Regelkern implementieren, ohne Shopsystem-Bezug, trockenlauffaehig.
4. Adapter fuer das konkrete Shopsystem implementieren.
5. Ausloeser anbinden (Hook, Event, Cron).
6. Im Labor gegen absichtlich fehlerhafte Konfiguration testen.

Nicht mehr als einen Waechter gleichzeitig bauen.