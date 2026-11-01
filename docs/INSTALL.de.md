# Installation

Für osTicket 1.18.x. Jeder Schritt hat einen Prüfpunkt; schlägt er fehl, dort anhalten.

**Vorab:** Eine Regel ändert Tickets, ohne dass jemand klickt. Der Notausschalter (Schritt 0) funktioniert auch ohne Admin-Panel. Jede neue Regel startet im Probelauf (Schritt 5), bevor sie etwas ändert.

## 0. Notausschalter, vor allem anderen

Zwei Wege, alle Regeln zu stoppen:

- Admin-Panel, Verwalten, Plugins: „Ticket Automation“ deaktivieren (oder eine einzelne Instanz).
- Auf dem Server: `touch include/plugins/ticket-automation/DISABLED`. Wirkt ab dem nächsten Request; Datei löschen, um wieder einzuschalten.

## 1. Umgebung prüfen

- osTicket-Version unter Admin-Panel, Dashboard, Information: 1.18.x.
- PHP 8.0 oder neuer.
- Für den Fälligkeits-Auslöser: Ruft ein Cronjob `api/cron.php` auf, oder ist Auto-Cron aktiv (Admin-Panel, Einstellungen, System)? Ohne beides läuft der Fälligkeits-Auslöser nie.

**Abbruch**, wenn osTicket älter als 1.18 ist.

## 2. Dateien kopieren

```bash
cd /pfad/zu/osticket/include/plugins
unzip ticket-automation-<version>.zip      # legt ticket-automation/ an
chown -R <web-user>: ticket-automation
```

Prüfpunkt: `include/plugins/ticket-automation/plugin.php` existiert und ist für den Webserver-Benutzer lesbar. Im Ordner liegt keine Datei `DISABLED`.

## 3. Plugin installieren und aktivieren

Admin-Panel, Verwalten, Plugins, Add New Plugin, „Ticket Automation“, Install. Dann öffnen und Status auf Active setzen.

Prüfpunkt: Das Plugin steht als aktiv in der Liste. Noch ändert sich nichts; ohne Instanz gibt es keine Regel.

## 4. Status nachsehen

Admin-Panel, Verwalten, Listen, Ticket Status. Notieren, welche Status offen und aktiviert sind und welche die Regeln beobachten und setzen sollen. Deaktivierte Status kann eine Regel nicht setzen.

## 5. Erste Regel, im Probelauf

Plugin, Instances, Add New Instance:

1. Name: was die Regel tut, z. B. „Kunde hat geantwortet“. Der Name steht in jeder Notiz.
2. Status: Enabled.
3. **Dry run: an.**
4. Auslöser, Bedingungen, Aktionen wie in den [Beispielen der README](../README.de.md#beispielregeln).
5. Speichern.

Prüfpunkt: Die Instanz speichert ohne Fehlermeldung. Eine Fehlermeldung nennt die Einstellung, die zu ändern ist (z. B. ein deaktivierter Zielstatus oder eine Fälligkeitsregel, die das Ticket nicht weiterbewegt). Kommt der Fehler beim Neuanlegen, hat osTicket womöglich schon eine leere, deaktivierte Instanz angelegt: vor dem nächsten Versuch löschen.

## 6. Smoke-Test

An einem Testticket in einem beobachteten Status:

| Fall | Erwartet |
|---|---|
| Als Kunde antworten (Portal oder Mail) | Im System-Log (Admin-Panel, Dashboard, System-Logs) steht „Dry run: rule ... would run on ticket #...“ mit den geplanten Änderungen. Das Ticket ist unverändert |
| Agenten-Antwort auf dasselbe Ticket | Kein neuer Eintrag (außer die Regel beobachtet Agenten-Antworten) |
| Fälligkeitsregel: Fälligkeitsdatum in die Vergangenheit setzen, Cron abwarten | Probelauf-Eintrag für dieses Ticket |

Dann Dry run ausschalten und den ersten Fall wiederholen:

| Fall | Erwartet |
|---|---|
| Als Kunde antworten | Status wechselt wie eingestellt; eine interne Notiz „Ticket Automation“ von SYSTEM; keine Mail des Plugins an den Kunden |
| Abwesenheitsnotiz per Mail | Keine Änderung (mit „Ignore bounces and auto-replies“ an) |

**Abbruch** und Instanz deaktivieren, wenn eine Regel ein Ticket ändert, das sie nicht ändern soll.

## 7. Security-Gate

| Prüfung | Erwartet |
|---|---|
| Kunde antwortet zweimal hintereinander | Ein Statuswechsel, eine Notiz pro Nachricht, keine wachsende Kette von Notizen |
| Agent schreibt eine interne Notiz auf ein Ticket, das eine Notiz-Regel beobachtet | Genau eine Automations-Notiz pro Agenten-Notiz |
| Zielstatus in der Statusliste deaktivieren, dann Regel auslösen | Ticket unverändert; Warnung im System-Log „refused ... disabled in the status list“ |
| `DISABLED` im Plugin-Ordner anlegen, Regel auslösen | Ticket unverändert, kein Log-Eintrag. Datei danach löschen |
| Postfach des Testkunden | Keine Mail vom Plugin. Mails, die osTicket selbst verschickt (Auto-Antworten, Mitleser-Hinweise), ändert das Plugin nicht |

## Aktualisieren

Ordnerinhalt ersetzen, Instanzen bleiben. Vorher das CHANGELOG auf geänderte Einstellungen lesen.

## Deinstallieren

Plugin deaktivieren, Instanzen löschen, unter Plugins deinstallieren, Ordner entfernen. Bereits geschriebene Notizen bleiben in den Tickets.
