# Widerrufsbutton für WooCommerce

WordPress-Plugin, das die seit dem **19. Juni 2026** geltende gesetzliche Widerrufsfunktion
(EU-Richtlinie 2023/2673, in Deutschland § 356a BGB) für WooCommerce-Shops umsetzt.

Verbraucher:innen können online geschlossene Fernabsatzverträge (WooCommerce-Bestellungen)
genauso einfach widerrufen, wie sie geschlossen wurden — über einen gut sichtbaren,
loginfreien Button mit zweistufiger Bestätigung und automatischer Eingangsbestätigung per E-Mail.

## Funktionsumfang

- **Loginfreier Sticky-Button** sitewide (Position unten rechts/links/mittig), optional zusätzlicher Footer-Textlink und `[widerrufsbutton]`-Shortcode.
- **Barrierearmes Modal** mit Background-Blur, Fokus-Trap, ESC-Schließen, `role="dialog"`/`aria-modal`.
- **Zweistufiger Ablauf:** Identifikation → gesonderte verbindliche Bestätigung. Kein Pflicht-Grund (optionales Freitextfeld).
- **Eingeloggt:** bestellbezogene Auswahl der eigenen, noch widerrufbaren Bestellungen.
- **Gast:** Abgleich über E-Mail + Bestellnummer mit flexiblem Nummern-Matching; optionale E-Mail-Verifizierung (Bestätigungslink).
- **Artikel- und Bestellbezug:** ganze Bestellung oder einzelne Position; auf Produktseiten wird die Artikelnummer vorausgefüllt.
- **Produktseiten-/Kundenkonto-Integration:** Button im Konto je Bestellung mit Vorauswahl.
- **Automatische Eingangsbestätigung** (dauerhafter Datenträger) inkl. Datum + Uhrzeit, plus Betreiber-Benachrichtigung – über die WooCommerce-Mailer-Infrastruktur, Templates überschreibbar.
- **Eigene Datenbanktabelle** mit Datensnapshot beim Eingang und Aktivitäts-Log.
- **Admin-Backend** unter WooCommerce: Liste (Filter/Suche/Sortierung), Detailansicht mit Statuswechsel und Verlauf, 30-Tage-Statistik, CSV-Export.
- **Produkt-Ausschlüsse** (Typ/Kategorie/Einzelprodukt) und **Duplikat-Prüfung**.
- **HPOS-kompatibel**, vollständige i18n (mitgelieferte `.pot`), Default-Sprache Deutsch.

## Anforderungen

- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+

## Installation

1. Aktuelles ZIP unter [Releases](https://github.com/kallekallovsky/wc-widerrufsbutton/releases/latest) herunterladen und über *Plugins → Installieren → Plugin hochladen* einspielen.
2. Plugin im WordPress-Backend aktivieren (WooCommerce muss aktiv sein). Bei Aktivierung werden die Tabellen `wp_wc_widerrufe` und `wp_wc_widerrufe_log` angelegt.
3. Unter **WooCommerce → Widerruf-Einstellungen** konfigurieren.
4. Betreff/Texte der E-Mails unter **WooCommerce → Einstellungen → E-Mails** anpassen.

## Updates

Das Plugin meldet neue Versionen selbst unter *Plugins → Aktualisieren* — wie ein Plugin aus dem
WordPress.org-Verzeichnis, inklusive Auto-Update-Schalter. Grundlage sind die GitHub-Releases
dieses Repositorys; die Prüfung übernimmt der mitgelieferte
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (`lib/`).

Sollte das Repository wieder auf privat gestellt werden, ist zusätzlich ein GitHub-Token
mit Lesezugriff nötig — in der `wp-config.php`:

```php
define( 'WDBTN_GITHUB_TOKEN', 'github_pat_...' );
```

Solange das Repository öffentlich ist, wird die Konstante nicht benötigt.

## Release veröffentlichen

Releases entstehen automatisch aus `main` — kein manuelles Taggen, kein ZIP-Upload:

1. Version in `widerrufsbutton-fuer-woocommerce.php` erhöhen, an **beiden** Stellen:
   im Plugin-Header (`Version:`) und in `define( 'WDBTN_VERSION', ... )`.
   Der Workflow bricht ab, falls die beiden auseinanderlaufen.
2. Commit und Push auf `main`.

Der Workflow [`release.yml`](.github/workflows/release.yml) prüft dann die PHP-Syntax, baut ein
ZIP ohne Entwicklungsdateien (`PLAN.md`, `.github/`, …) und legt Tag plus Release `v<Version>` an.
Existiert für die Version bereits ein Release, passiert nichts — Pushes ohne Versionssprung
lösen also kein Release aus.

## Konfiguration (Auszug)

- **Anzeige:** Button-Text, Position, Sichtbarkeit (sitewide / Produktseite / Kundenkonto / Footer-Link), Footer-Link-Text.
- **Ablauf & Benachrichtigung:** Gast-Verifizierung, additive Bestellnotiz, Ablehnungs-Mail, Empfänger der Betreiber-Mail.
- **Fristlogik:** Tage (Default 14), Kulanzpuffer (Default 1) und Berechnungsbasis (Bestelldatum / Abschlussdatum) – **datumsbasiert**, nicht statusbasiert. Gerechnet wird in Kalendertagen: der Bestelltag zählt nicht mit, die Frist endet um 24:00 Uhr des letzten Tages (§§ 187, 188 BGB).
- **Ohne passende Bestellung:** Widerrufe werden per Default auch dann angenommen, wenn sich keine Bestellung zuordnen lässt (Status *Nicht zugeordnet*).
- **Produkt-Ausschlüsse:** nach Typ, Kategorie oder einzelnen Produkt-IDs – markieren zur Prüfung, blockieren nicht.
- **Datenschutz:** Aufbewahrungsfrist (Default 0 = keine automatische Löschung), Anbindung an *Werkzeuge → Persönliche Daten*, optionales Löschen aller Daten bei Deinstallation.

## Leitplanken

Diese Entscheidungen sind bewusst so getroffen – bitte vor Änderungen den jeweiligen Grund lesen:

- **Ein Widerruf wird nie hart blockiert.** Weder eine abgelaufene Frist noch ein Produkt-Ausschluss noch eine unbekannte Bestellnummer führen dazu, dass die Erklärung verworfen wird. Der Widerruf wird mit seinem **Zugang** wirksam (§ 130 BGB), nicht damit, dass der Shop ihn zuordnen oder akzeptieren will. Was strittig ist, wird erfasst, markiert und dem Betreiber zur Entscheidung vorgelegt.
- **Die Eingangsbestätigung ist Pflicht**, nicht Kür (§ 356a BGB). Schlägt der Versand fehl, bleibt der Widerruf trotzdem dokumentiert.
- **Fristen großzügig statt knapp.** Das Referenzdatum entspricht selten dem gesetzlichen Fristbeginn – beim Warenkauf läuft die Frist erst ab Erhalt der Ware. Zu lange anzubieten kostet Kulanz, zu kurz anzubieten verwehrt ein bestehendes Recht.
- **Read-only gegenüber WooCommerce:** keine Statuswechsel, keine Rückerstattungen, nur additive Bestellnotizen.

## Funktionsweise des Widerrufs

1. Verbraucher:in öffnet das Modal über den Button.
2. **Schritt 1 – Identifikation:** eingeloggt per Bestellauswahl, als Gast per Name, Bestellnummer und E-Mail.
3. **Schritt 2 – Bestätigung:** gesonderte Schaltfläche „Widerruf verbindlich bestätigen".
4. Bei Gästen mit aktivierter Verifizierung wird zunächst ein Bestätigungslink per E-Mail versendet; erst nach Klick gilt der Widerruf als eingegangen.
5. Der Widerruf wird mit Snapshot gespeichert, eine Eingangsbestätigung an die Verbraucher:in sowie eine Benachrichtigung an den Betreiber versendet.

## Kompatibilität mit Warenwirtschaft (Billbee & Co.)

Das Plugin arbeitet **read-only** gegenüber WooCommerce-Bestellungen:

- **kein** Wechsel des WooCommerce-Bestellstatus, **keine** Refunds, **keine** Bestandsänderungen,
- die Fristlogik basiert auf dem **Bestell-/Lieferdatum**, nicht auf dem Status (der von Billbee umgeschrieben werden kann),
- beim Eingang wird ein **unabhängiger Datensnapshot** gespeichert (bleibt erhalten, auch wenn Billbee WC-Kundendaten später anonymisiert),
- optional wird rein additiv eine **Bestellnotiz** angehängt (abschaltbar).

Eine spätere **Billbee-API-Anbindung (Phase 2)** ist über die gekapselte Schnittstelle
`Widerrufsbutton\Notifier` vorbereitet (Default: `Null_Notifier`, No-op) und per Filter `wdbtn_notifier` austauschbar.

## Hooks (Auswahl)

- `wdbtn_withdrawal_created` ( int $id, array $record ) — nach finaler Erfassung.
- `wdbtn_verification_requested` ( int $id, array $record, string $token ) — Gast-Verifizierung.
- `wdbtn_status_changed` ( int $id, string $status, string $note ) — Statuswechsel im Backend.
- `wdbtn_notifier` (Filter) — eigene `Notifier`-Implementierung einhängen (Phase 2).
- `wdbtn_rate_limit_max` / `wdbtn_rate_limit_window` (Filter) — Rate-Limit anpassen.

## E-Mail-Templates überschreiben

Templates liegen in `templates/emails/` und lassen sich im Theme überschreiben:

```
yourtheme/woocommerce/emails/confirmation-customer.php
yourtheme/woocommerce/emails/notification-admin.php
```

## Daten & DSGVO

Es werden nur die für den Widerruf erforderlichen Daten gespeichert (Datensparsamkeit):
Name, E-Mail, Bestellnummer/-bezug, optionaler Grund, Zeitpunkt sowie ein **gehashter** IP-Wert.
Bei der Deinstallation können alle Daten optional vollständig entfernt werden (`uninstall.php`).

## Übersetzung

Alle Strings sind übersetzbar (Textdomain `widerrufsbutton-fuer-woocommerce`).
Die Vorlage liegt unter `languages/widerrufsbutton-fuer-woocommerce.pot`.

## Rechtlicher Disclaimer

Dieses Plugin setzt die technischen Anforderungen der EU-Richtlinie 2023/2673 bzw. § 356a BGB
nach bestem Wissen um. Es stellt **keine Rechtsberatung** dar und ersetzt nicht die bestehende
Widerrufsbelehrung oder andere Widerrufswege. Die Vorauswahl widerrufbarer Bestellungen ist eine
sinnvolle Hilfe, trifft aber keine rechtsverbindliche Entscheidung; das Plugin blockiert einen
Widerruf nicht hart. Die finale rechtliche Prüfung der Einrichtung, Konfiguration und der
gesetzlichen Pflichten obliegt dem Shop-Betreiber.

## Changelog

### 0.1.0
- Erste Version: Sticky-Button + Modal (zweistufig), Submission mit Snapshot, Eingangsbestätigung + Betreiber-Mail, eingeloggte Bestellauswahl, flexibler Gast-Abgleich, Gast-Verifizierung, datumsbasierte Fristlogik, Produkt-Ausschlüsse, Duplikat-Prüfung, Admin-Übersicht mit Statistik/Log/CSV, Einstellungsseite, HPOS-Kompatibilität, read-only/Billbee-konform, i18n.
