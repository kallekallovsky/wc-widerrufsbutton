# Widerrufsbutton für WooCommerce

WordPress-Plugin, das die seit dem **19. Juni 2026** geltende gesetzliche Widerrufsfunktion
(EU-Richtlinie 2023/2673, in Deutschland § 356a BGB) für WooCommerce-Shops rechtskonform umsetzt.

Verbraucher:innen können online geschlossene Fernabsatzverträge (WooCommerce-Bestellungen)
genauso einfach widerrufen, wie sie geschlossen wurden — über einen gut sichtbaren,
loginfreien Button mit zweistufigem Bestätigungsverfahren und automatischer
Eingangsbestätigung per E-Mail.

## Status

In aktiver Entwicklung. Die Umsetzungs-Roadmap steht in [`PLAN.md`](PLAN.md).

## Eckpunkte

- Loginfreier Sticky-Button sitewide + barrierearmes Modal (Fokus-Trap, Background-Blur)
- Zweistufiger Ablauf (Identifikation → gesonderte verbindliche Bestätigung)
- Eingeloggte Bestellauswahl + Gast-Abgleich mit flexiblem Bestellnummern-Matching
- Automatische Eingangsbestätigung auf dauerhaftem Datenträger (E-Mail)
- Eigene Datenbanktabelle mit Datensnapshot beim Eingang
- Admin-Übersicht unter dem WooCommerce-Menü
- HPOS-kompatibel, **read-only** gegenüber WooCommerce-Bestellungen (Billbee-kompatibel)

## Installation

1. Ordner `widerrufsbutton-fuer-woocommerce` nach `wp-content/plugins/` kopieren.
2. Plugin im WordPress-Backend aktivieren (WooCommerce muss aktiv sein).
3. Unter **WooCommerce → Widerrufe → Einstellungen** konfigurieren.

## Rechtlicher Disclaimer

Dieses Plugin setzt die technischen Anforderungen der EU-Richtlinie 2023/2673 bzw. § 356a BGB
nach bestem Wissen um. Es stellt **keine Rechtsberatung** dar und ersetzt nicht die bestehende
Widerrufsbelehrung oder andere Widerrufswege. Die finale rechtliche Prüfung der Einrichtung und
Konfiguration obliegt dem Shop-Betreiber.
