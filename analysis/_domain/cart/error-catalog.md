# Fehlerkatalog Warenkorb & Checkout

Sammlung aller bekannten Fehlerfaelle der Domaene Warenkorb/Checkout.
Grundlage fuer die Waechter des Cart Shield (siehe CLAUDE.md, Produktziel).

Die Faelle sind bewusst shopsystem-neutral formuliert. WooCommerce-Bezuege
stehen nur in der Spalte "Hinweis" und sind nicht Teil der Fachlogik.

---

## Achse 1 - Fachgruppen

### A - Preis & Marge

| Nr | Untergruppe | Fall |
|---|---|---|
| 1 | A1 Rabattlogik | Ruinoese Rabatt-Konflikte, unabsichtliches Stacking von Gutscheinen |
| 20 | A1 | Free-Shipping-Schwelle wird vor Rabattabzug geprueft |
| 21 | A1 | Gutschein uebersteigt Warenkorbwert, Versand wird mitgeschluckt |
| 22 | A1 | Gutschein ohne Ablauf- oder Nutzungslimit, landet auf Coupon-Portalen |
| 18 | A2 Rechen-/Anzeigekorrektheit | Veralteter Preis-Cache: Produktseite zeigt abgelaufenen Sale-Preis |
| 19 | A2 | Rundungsfehler bei Prozentrabatt und Netto/Brutto-Umrechnung |
| 9 | A3 Zahlungskosten & Risiko | Teure oder riskante Zahlarten bei Kleinstbetraegen bzw. Erstbestellung |
| 11 | A4 Waehrung | Waehrungs-Drift: veralteter Wechselkurs durch Cache oder toten Cronjob |

### B - Steuern & Recht

| Nr | Untergruppe | Fall |
|---|---|---|
| 8 | B1 Steuersaetze | Cross-Border: fehlende Steuersaetze oder OSS-Schwellenwerte |
| 23 | B1 | Versandmethode auf Steuerstatus "keine Steuer" - Versandsteuer faellt aus |
| 24 | B1 | Steuerbasis auf Shop-Adresse statt Lieferadresse |
| 13 | B2 Kundengruppen | B2B/B2C-Kollision: Gutschein ohne Kundengruppen-Einschraenkung |
| 25 | B2 | Reverse Charge ohne Pruefung der USt-IdNr. |

### C - Produkt- & Katalogdaten

| Nr | Untergruppe | Fall |
|---|---|---|
| 4 | C1 Pflichtattribute | Fehlendes Gewicht oder Mass blockiert Versandberechnung |
| 12 | C2 Struktur | Bundle-Zerfall: Komponente unsichtbar oder Preis 0 |
| 28 | C2 | Variante ohne Preis, ohne Bestand oder mit abweichender Backorder-Regel |
| 29 | C2 | Digitale und physische Produkte gemischt, Versandpflicht falsch |
| 2 | C3 Referenzintegritaet | Loeschen einer Versandklasse, die noch zugewiesen ist |
| 10 | C3 | SKU-Aenderung ohne Abgleich mit ERP |

### D - Bestand & Versand

| Nr | Untergruppe | Fall |
|---|---|---|
| 7 | D1 Bestandsfuehrung | Overselling bei Flash Sales durch asynchronen ERP-Sync |
| 6 | D2 Versandberechnung | Geister-Versand: schweres Produkt in Standard-Versandklasse |
| 26 | D2 | Ueberlappende oder falsch sortierte Versandzonen |
| 27 | D2 | Keine Versandmethode verfuegbar - Checkout blockiert ohne Meldung |
| 36 | D2 | Versandzone deckt ein Lieferland nur teilweise ab (Bundesland- oder PLZ-Beschraenkung), Rest des Landes faellt still durch |

### E - Externe Systeme

| Nr | Untergruppe | Fall |
|---|---|---|
| 30 | E1 Zahlungsdienstleister | Testmodus mit Live-Keys oder fehlendes SSL - Zahlart verschwindet |
| 31 | E1 | Zahlung erfolgreich, Webhook verloren, Bestellung bleibt offen |
| 5 | E2 Logistik-/Steuer-APIs | Temporaerer Ausfall externer Dienstleister-APIs |
| 34 | E3 ERP / Warenwirtschaft | Hintergrund-Job-Queue verstopft, Sync und Mails laufen nicht |

### F - Laufzeit & Systemzustand

| Nr | Untergruppe | Fall |
|---|---|---|
| 16 | F1 Session & Cache | Warenkorb leert sich durch Cache, Cookie-Policy oder DB-Fehler |
| 17 | F1 | Session-Vermischung: Kunde sieht fremden Warenkorb |
| 3 | F2 Erweiterungen & Updates | Checkout-Ausfall nach Plugin- oder Core-Update |
| 15 | F2 | Verwaiste Cronjobs und Recovery-Links eines deaktivierten Plugins |
| 32 | F2 | Zahlart ohne Unterstuetzung des neuen Checkout-Typs (Block vs. Classic) |
| 33 | F2 | Veraltete Theme-Template-Overrides nach Update |
| 14 | F3 Nebenlaeufigkeit | Maximalbestellmenge per paralleler API-Requests umgangen |
| 35 | F3 | Preis-Manipulation ueber API durch unsicheren Fremdcode-Hook |

---

## Achse 2 - Pruefebene

Bestimmt Aufwand und Portierbarkeit eines Waechters.

| Ebene | Pruefzeitpunkt | Gruppen | Aufwand | Portierbar |
|---|---|---|---|---|
| 1 Konfiguration | einmalig, per Cron | A1, B1, C1, C3, D2 | niedrig | hoch |
| 2 Daten | beim Speichern eines Objekts | C1, C2, C3 | niedrig | hoch |
| 3 Warenkorb-Laufzeit | bei jeder Berechnung | A2, A3, A4, B2, D1, D2 | mittel | mittel |
| 4 Schnittstelle | bei jedem externen Aufruf | E1, E2, E3 | mittel | mittel |
| 5 Plattform-Zustand | fortlaufendes Monitoring | F1, F2, F3 | hoch | niedrig |

Die Ebenen 1 und 2 sind fast vollstaendig portabel und daher die Startzone.
Ebene 5 ist fachlich am wertvollsten, aber pro System neu zu bauen.

---

## Bearbeitungsstand

| Fall | Status | Waechter |
|---|---|---|
| 27 | Adapter-Vertrag im Entwurf, kein Code vorhanden | geplant: Pruefebene 1 (Konfiguration), meldet nur |
| 36 | neu erfasst, aus der Vertragsarbeit zu Fall 27 abgeleitet | offen |
| alle uebrigen | erfasst, nicht bearbeitet | offen |

Artefakte zu Fall 27:
`analysis/_domain/cart/guardians/27-no-shipping-method/contract.md` (Adapter-Vertrag),
`analysis/_domain/cart/flow-shipping-calculation.md` (Ablauf, Abschnitt 9).

---

## Offene Fragen

- Fehlalarm-Toleranz je Fall: welche Faelle duerfen automatisch eingreifen,
  welche nur warnen.
- Welche Faelle sind wirklich systemuebergreifend, welche nur in
  WooCommerce relevant. Pro Fall zu pruefen, sobald ein zweites System
  angebunden wird.
