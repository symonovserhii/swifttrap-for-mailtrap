=== SwiftTrap for Mailtrap ===
Contributors: simmotorlp
Tags: mailtrap, transactional-email, email-api, wp-mail, email-log
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Versenden Sie WordPress-E-Mails über die Mailtrap Email API (nicht SMTP). Bulk- und transaktionale Streams, Kategorien, Unterdrückungsliste, E-Mail-Protokoll.

== Description ==

**SwiftTrap** ist ein direkter Ersatz für `wp_mail()`, der WordPress-E-Mails über die **Mailtrap Email Sending API** statt über SMTP leitet. Es ist speziell für Mailtrap entwickelt – kein generisches SMTP-Plugin mit Mailtrap-Voreinstellung – und bietet daher Mailtrap-native Funktionen, die SMTP nicht kann: Weiterleitung über Bulk- oder Transactional-Streams, E-Mail-Kategorien, benutzerdefinierte Variablen für Tracking, Unterdrückungslisten und Domain-Verifizierungsstatus.

= Warum HTTP-API statt SMTP? =

* **Geringere Latenz** — ein HTTPS-Aufruf pro Nachricht, keine MAIL FROM / RCPT TO / DATA Round-Trips.
* **Bessere Zustellbarkeit** — Mailtrap leitet API-Nachrichten über seine dedizierten Transactional- und Bulk-Streams; SMTP bietet keine Stream-Auswahl.
* **Native Kategorien** — jede E-Mail wird automatisch kategorisiert (welcome, password-reset, notification, marketing usw.), sodass Sie sie in Mailtrap filtern und auswerten können.
* **Keine Firewall-Probleme** — Port 587/465 blockiert? Die API funktioniert über das Standard-HTTPS 443.

= Warum SwiftTrap und nicht WP Mail SMTP / Post SMTP =

* Generische SMTP-Plugins verwenden die SMTP-Zugangsdaten von Mailtrap und verlieren dabei jede Mailtrap-exklusive Funktion.
* SwiftTrap ruft `send.api.mailtrap.io` für transaktionale E-Mails und `bulk.api.mailtrap.io` für Bulk-E-Mails auf — automatisch, je nach Kategorie oder per Filter.
* Kein Mailtrap-PHP-SDK erforderlich. Das Plugin ist **insgesamt ~30 KB groß** und nutzt ausschließlich die WordPress-HTTP-API (`wp_remote_post`).
* Die Statistikseite zeigt den Verifizierungsstatus Ihrer Absender-Domain und die Live-Unterdrückungsliste (Bounces, Beschwerden, Abmeldungen).

= Funktionen =

* Direkter Ersatz für `wp_mail()` — funktioniert mit WooCommerce, Contact Form 7, Gravity Forms und jedem Plugin, das den WordPress-Mailversand nutzt.
* Automatische E-Mail-Kategorisierung und Überschreibung der Stream-Weiterleitung über ein Einstellungsraster.
* Zustellungsverfolgung & Webhooks — Echtzeit-Ereignisverfolgung über die eigene REST-Route `swifttrap/v1/webhook`.
* Unterdrückungsverwaltung — CRUD-Panel für Mailtrap-Unterdrückungslisten mit Prüfung unterdrückter Empfänger vor dem Versand.
* Zuverlässigkeits-Fallback — Sanfter Rückfall auf das native WordPress-`wp_mail()`, falls der Mailtrap-API-Aufruf fehlschlägt.
* Site-Health-Integration — Prüftest für den Mailtrap-Token-Status und die Verifizierung der Absender-Domain.
* Live-E-Mail-Protokoll — Zustelldaten direkt aus der Mailtrap-API durchsuchen und filtern; Suche nach Empfängeradresse, Status oder Zeitraum mit automatischer Seitennummerierung.
* WP-CLI-Befehle — Verwaltung über die Kommandozeile mit `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Anhang-Größenschutz — Konfigurierbare Limits, damit überdimensionierte Dateien nicht am API-Gateway abgelehnt werden.
* Schaltfläche für Test-E-Mails auf der Einstellungsseite.
* Unterstützung von Mailtrap-Vorlagen über `template_uuid`.
* Fällt auf den Standard-WordPress-Mail-Handler zurück, wenn deaktiviert oder das Token leer ist.

= Erweiterbar über Filter =

* `swifttrap_mailtrap_email_category` — überschreibt die automatisch erkannte E-Mail-Kategorie.
* `swifttrap_mailtrap_use_bulk_stream` — erzwingt für eine Nachricht den Bulk- oder Transactional-Stream.
* `swifttrap_mailtrap_template` — sendet über eine Mailtrap-Vorlage anhand von `template_uuid`.
* `swifttrap_mailtrap_custom_variables` — hängt Tracking-Metadaten an ausgehende E-Mails an.

= Datenschutz =

Dieses Plugin sendet E-Mail-Inhalte (Empfänger, Betreff, Text, Anhänge) an die Mailtrap-API unter `send.api.mailtrap.io` und `bulk.api.mailtrap.io`. Kontostatistiken und E-Mail-Protokolle werden von `mailtrap.io/api/accounts` und `mailtrap.io/api/email_logs` abgerufen. Siehe die [Mailtrap-Datenschutzrichtlinie](https://mailtrap.io/privacy-policy). Es werden keine Daten an andere Stellen gesendet.

== Installation ==

1. Installieren Sie das Plugin über **Plugins → Installieren** und suchen Sie nach *SwiftTrap for Mailtrap*, oder laden Sie den Ordner `swifttrap-for-mailtrap` nach `/wp-content/plugins/` hoch.
2. Aktivieren Sie das Plugin.
3. Gehen Sie zu **Mailtrap → Einstellungen**.
4. Fügen Sie Ihr Mailtrap-**Send-API-Token** ein (Mailtrap-Dashboard → Sending Domains → API Tokens).
5. Legen Sie Ihre verifizierte Absender-E-Mail und Ihren Absendernamen fest.
6. Klicken Sie auf **Test-E-Mail senden**, um die Zustellung zu überprüfen.

== Frequently Asked Questions ==

= Warum SwiftTrap statt WP Mail SMTP oder Post SMTP mit Mailtrap-Zugangsdaten verwenden? =

WP Mail SMTP und Post SMTP leiten über das SMTP-Gateway von Mailtrap und behandeln Mailtrap wie jeden anderen SMTP-Host. SwiftTrap nutzt die HTTP-Send-API von Mailtrap, die Funktionen bietet, die SMTP nicht kann: Weiterleitung über Bulk- oder Transactional-Streams, Kategorien, benutzerdefinierte Tracking-Variablen, Template-UUIDs und Live-Einblick in die Unterdrückungsliste. Verwenden Sie SwiftTrap, wenn Sie Mailtrap-natives Verhalten möchten; verwenden Sie ein generisches SMTP-Plugin, wenn Sie eine Einheitskonfiguration für alle Anbieter wünschen.

= Unterstützt es Mailtrap-E-Mail-Vorlagen? =

Ja — verwenden Sie den Filter `swifttrap_mailtrap_template`, um über eine `template_uuid` zu senden. Die Vorlagenvariablen können über die Standard-Payload für Template-Variablen von Mailtrap übergeben werden.

= Wie funktioniert die Weiterleitung über den Bulk-Stream? =

Standardmäßig werden Marketing-/Werbekategorien an `bulk.api.mailtrap.io` weitergeleitet, alles andere an `send.api.mailtrap.io`. Überschreiben Sie dies pro Nachricht mit dem Filter `swifttrap_mailtrap_use_bulk_stream` — nützlich für Massen-Newsletter aus einem eigenen Plugin.

= Wo erhalte ich mein API-Token? =

Melden Sie sich bei [mailtrap.io](https://mailtrap.io) an, öffnen Sie Ihre Absender-Domain, gehen Sie zu **API Tokens** und erstellen Sie ein Token mit Sendeberechtigungen.

= Was passiert, wenn ich das Plugin deaktiviere oder das Token entferne? =

WordPress fällt auf seinen Standard-`wp_mail()`-Handler zurück. Es werden keine E-Mails stillschweigend verworfen.

= Benötigt das Plugin das Mailtrap-PHP-SDK? =

Nein. SwiftTrap ruft die Mailtrap-REST-API direkt über die WordPress-HTTP-API auf. Die Gesamtgröße des Plugins beträgt rund 30 KB.

= Welche Daten werden extern gesendet? =

E-Mail-Daten (Empfänger, Betreff, Text, Anhänge) werden an `send.api.mailtrap.io` und `bulk.api.mailtrap.io` gesendet. Kontostatistiken werden von `mailtrap.io/api/accounts` abgerufen. Siehe die [Mailtrap-Datenschutzrichtlinie](https://mailtrap.io/privacy-policy).

= Gibt es ein Größenlimit für Anhänge? =

Ja — 25 MB pro E-Mail (entspricht dem API-Limit von Mailtrap).

== Screenshots ==

1. Einstellungsseite — API-Token, verifizierter Absender, Stream-Weiterleitung.
2. Statistikseite — Verifizierungsstatus der Absender-Domain und Unterdrückungsliste (Bounces, Beschwerden, Abmeldungen).
3. E-Mail-Protokoll — Live-Daten aus der Mailtrap-API mit Filtern und Seitennummerierung.
4. Dashboard-Widget mit Integrationsstatus, Absender und Schnelllinks zu Statistiken und Einstellungen.
5. Bestätigung der Test-E-Mail.

== Changelog ==

= 3.0.1 =
* Behoben: Der Webhook-Empfänger überprüft nun den tatsächlichen `Mailtrap-Signature`-HMAC-SHA256-Header von Mailtrap, statt einen Header, den Mailtrap nie sendet. Jeder echte Webhook-Aufruf zur Zustellungsverfolgung wurde seit der Einführung der Funktion in 2.4.0 rundweg abgelehnt.
* Behoben: Das Parsen der Webhook-Payload entpackt nun die `{"events": [...]}`-Hülle von Mailtrap korrekt, sodass verifizierte Ereignisse `do_action('swifttrap_mailtrap_webhook_event', ...)` erreichen.
* Behoben: Die Nutzungskarte auf der Statistikseite ruft nun den aktuellen `/api/billing/usage`-Endpunkt von Mailtrap auf, statt eines veralteten kontobezogenen Pfads, der keine Daten zurückgab.
* Behoben: Beim Deinstallieren des Plugins werden nun die tatsächlich zwischengespeicherten Transients gelöscht, statt Schlüsselnamen aus der Zeit vor 2.3.0, die nicht mehr übereinstimmen.
* Verbessert: Die Empfängersuche in den E-Mail-Protokollen und die Konto-API-Aufrufe verwenden nun durchgängig die Filter-Syntax mit eckigen Klammern und die Bearer-Token-Authentifizierung.

= 3.0.0 =
* Breaking Change: Die gesamte lokale dateibasierte E-Mail-Protokollierung wurde entfernt. Es werden keine Protokolldateien mehr auf die Festplatte geschrieben — das eliminiert das Risiko von OOM/vollem Datenträger bei Websites mit hohem Volumen.
* Neu: Das Panel „E-Mail-Protokolle" auf der Statistikseite lädt Live-Daten direkt aus der Mailtrap-API (`GET /api/email_logs`).
* Neu: Die E-Mail-Protokolle unterstützen Filterung nach Empfänger-E-Mail-Adresse, Zustellstatus und Zeitraum.
* Neu: Clientseitige Seitennummerierung — puffert bis zu 1.000 Einträge von Mailtrap pro API-Aufruf und zeigt jeweils 20 Zeilen mit Zurück/Weiter-Navigation an. Der nächste Stapel wird automatisch geladen, sobald der Puffer erschöpft ist.
* Neu: Der Webhook-Handler löst nun `do_action('swifttrap_mailtrap_webhook_event', $event)` für jedes Zustellungsereignis aus und ermöglicht so Integrationen von Drittanbietern, ohne das Plugin zu verändern.
* Entfernt: CSV-Export, Löschen der Protokolldatei, Detail-Modal für Protokolleinträge, erneutes Senden von Protokollen, Einstellung für Einträge pro Seite und cron-basierte Protokollbereinigung. Alles wurde durch die Live-API-Ansicht ersetzt.
* Behoben: Die Statistikseite erzeugt kein überflüssiges Nonce-Attribut mehr auf dem Wrapper-Element.

= 2.4.2 =
* Behoben: Das E-Mail-Protokoll verlor die meisten Einträge bei hohem Versandvolumen oder gleichzeitigem Versand. Jeder Schreibvorgang las die gesamte Protokolldatei neu ein und schrieb sie neu, sodass parallele Prozesse sich gegenseitig überschrieben. Schreibvorgänge nutzen nun ein atomares, exklusiv gesperrtes Anhängen, sodass das Statistik-Dashboard (Sendungen pro Tag, Kategorien, Gesamtzahlen) die tatsächliche Anzahl gesendeter E-Mails widerspiegelt.
* Verbessert: Die Protokollierung verlangsamt große Mailings nicht mehr — Anhängevorgänge sind O(1), statt bei jeder E-Mail die gesamte Datei neu einzulesen und neu zu schreiben.

= 2.4.1 =
* Behoben: Die Unterdrückungsliste liest nun das `type`-Feld von Mailtrap, sodass das Dashboard echte BOUNCE-/COMPLAINT-/UNSUBSCRIBE-/MANUAL-Zahlen anzeigt, statt jeden Eintrag als manuell zu kennzeichnen.
* Neu: Zeilen der Unterdrückungsliste zeigen die Bounce-Kategorie der Nachricht an (sofern vorhanden), für Details zu Hard-Bounces.
* Behoben: Unterdrückungsdaten werden nun serverseitig im Datumsformat der Website formatiert, statt anhand der Browser-Spracheinstellung.
* Neu: Link „Alle in Mailtrap anzeigen" auf der Unterdrückungskarte.
* Neu: Auswahl der Einträge pro Seite (10/25/50/100) auf dem Bildschirm der E-Mail-Protokolle.
* Verbessert: Kopfzeilen-Aktionen der E-Mail-Protokolle rechtsbündig ausgerichtet; Eingabefeld für den Datumsfilter an die anderen Felder angeglichen.

= 2.4.0 =
* Neu: REST-Webhook-Endpunkt (`swifttrap/v1/webhook`) zur Verfolgung der Status „zugestellt", „bounced", „geöffnet" und „geklickt".
* Neu: CRUD-Unterdrückungsverwaltung in den Admin-Statistiken sowie Empfängerprüfungen vor dem Versand, um unterdrückte E-Mails zu überspringen.
* Neu: Fallback-Mechanismus, der bei einem API-Fehler `null` in `pre_wp_mail` zurückgibt, sodass stattdessen das native `wp_mail` die E-Mail sendet.
* Neu: Site-Health-Test für Verbindung und verifizierten Domain-Status.
* Neu: Überarbeitete Admin-Protokoll-Oberfläche mit Suche, Filterung, CSV-Export, iframe-Vorschau-Modals für Payloads und Aktionen zum erneuten Senden.
* Neu: Kategorie-Einstellungsraster für Regeln zur Kategorie-Stream-Zuordnung und Absenderüberschreibungen.
* Neu: WP-CLI-Namespace `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Neu: Einstellung für den Anhang-Größenschutz.
* Refactoring: CSV-Zeilenformatierer in eine Hilfsfunktion für Unit-Tests ausgelagert. Vollständig durch die Testsuite abgedeckt und verifiziert.

= 2.3.0 =
* PHP 8.0 ist nun die Mindestanforderung; getestet bis WordPress 7.0.
* Zuverlässigkeit: automatische Wiederholung mit Backoff bei vorübergehenden Mailtrap-API-Fehlern (429/5xx, berücksichtigt Retry-After).
* Deterministische Aufbewahrung der Protokolle über ein tägliches Cron-Ereignis (ersetzt die bisherige probabilistische Bereinigung).
* Konto-/Statistik-/Domain-/Unterdrückungs-Caches sind nun pro API-Token geschlüsselt, sodass ein Tokenwechsel keine veralteten Daten mehr liefert.
* Robuste JSON-Verarbeitung für alle Mailtrap-API-Antworten; Multisite-sicherer Einstellungs-Cache.
* Neu: Schaltfläche „Token überprüfen" auf dem Einstellungsbildschirm.
* Code auf PHP-8-Idiome modernisiert; erste Unit-Test-Suite hinzugefügt.

= 2.2.2 =
* Plugin-URI: zeigt nun auf die dedizierte Landingpage unter https://plugins.symonov.com/swifttrap-for-mailtrap/
* Keine Code- oder Verhaltensänderungen

= 2.2.1 =
* Readme: USP-orientierte Neufassung mit Fokus auf die Mailtrap Email API (statt SMTP) und die Weiterleitung über Bulk-/Transactional-Streams
* Tags: `email`/`mail`/`smtp` durch die gezielten Tags `mailtrap`, `transactional-email`, `email-api`, `wp-mail`, `email-log` ersetzt
* FAQ: Vergleich mit WP Mail SMTP / Post SMTP, Unterstützung von Mailtrap-Vorlagen und Weiterleitung über den Bulk-Stream hinzugefügt
* Getestet bis WordPress 7.0

= 2.2.0 =
* Alle Aufrufe von file_get_contents/file_put_contents durch die WP_Filesystem-API ersetzt
* $_GET-Sanitisierung mit korrektem wp_unslash() und phpcs-Annotationen korrigiert
* PHPDoc-Header in allen Dateien verbessert
* Bessere Einhaltung der WordPress Coding Standards

= 2.1.0 =
* Verifizierungsstatus der Absender-Domain auf der Statistikseite hinzugefügt
* Unterdrückungsliste (Bounces, Beschwerden, Abmeldungen) auf der Statistikseite hinzugefügt
* Filter `swifttrap_mailtrap_template` für die Unterstützung von Mailtrap-Vorlagen hinzugefügt
* Filter `swifttrap_mailtrap_custom_variables` für E-Mail-Tracking-Metadaten hinzugefügt
* Wiederverwendbare Funktion `swifttrap_mailtrap_get_account_id()` mit Transient-Caching extrahiert

= 2.0.0 =
* Abhängigkeit vom Mailtrap-SDK entfernt — nutzt direkt die WordPress-HTTP-API
* Keine externen Abhängigkeiten, ~30 KB Gesamtgröße des Plugins
* Verbesserte WP.org-Konformität

= 1.3.0 =
* Sicherheit: Protokollverzeichnis vor direktem Webzugriff geschützt
* Validierung der Anhanggröße hinzugefügt (Limit 25 MB)
* Validierung für leere Empfänger hinzugefügt
* Zeitzonenbehandlung in der Protokollanzeige korrigiert
* Berechnung der E-Mail-Kategorie optimiert
* Sperrung der Protokolldatei verbessert

== Upgrade Notice ==

= 3.0.1 =
Wichtige Korrektur: Webhook-Ereignisse zur Zustellungsverfolgung von Mailtrap wurden aufgrund einer fehlerhaften Signaturprüfung abgelehnt und seit 2.4.0 nie verarbeitet. Aktualisieren Sie, wenn Sie die Webhook-Integration nutzen.

= 2.4.0 =
Aktualisiert das WordPress-Plugin auf 2.4.0 und führt Webhooks zur Zustellungsverfolgung, Unterdrückungsverwaltung, sanften nativen Fallback, eine erweiterte Protokoll-Oberfläche mit CSV-Export, WP-CLI-Befehle und einen WordPress-Site-Health-Check ein.

= 2.3.0 =
Kleines Zuverlässigkeits-Release: automatische Sendewiederholungen bei vorübergehenden API-Fehlern, cron-basierte Protokollbereinigung und moderne PHP-8-Aktualisierungen.

= 2.2.2 =
Plugin-URI zeigt nun auf die dedizierte Landingpage auf plugins.symonov.com. Keine Code-Änderungen.

= 2.2.1 =
Reines Dokumentations-Release. Readme aufgefrischt und Kompatibilität mit WordPress 7.0 bestätigt.

= 2.2.0 =
WordPress-Coding-Standards-Durchgang — WP_Filesystem-API, gehärtete Eingabesanitisierung und verbessertes PHPDoc. Keine Konfigurationsänderungen erforderlich.
