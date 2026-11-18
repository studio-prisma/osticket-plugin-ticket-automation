# Ticket-Automation für osTicket

Regeln, die Tickets umstellen, wenn etwas passiert: Ein Kunde antwortet, ein Mitleser schreibt, ein Agent antwortet oder schreibt eine Notiz, oder ein Fälligkeitsdatum ist erreicht. Jede Regel hat Auslöser, Bedingungen und Aktionen. Jede Ausführung hinterlässt eine interne Notiz im Ticket.

Typischer Fall: Ein Ticket wartet auf den Kunden, der Kunde antwortet, das Ticket steht wieder auf „Offen“, ohne dass jemand es anfasst.

[English version](README.md)

## Voraussetzungen

- osTicket 1.18.x (getestet: 1.18.4)
- PHP 8.0 oder neuer (getestet: 8.3, 8.4)
- Für den Fälligkeits-Auslöser: osTicket-Cron (`api/cron.php` per Cronjob) oder Auto-Cron

## Installation

Kurz: nach `include/plugins/ticket-automation/` entpacken, unter Admin-Panel, Verwalten, Plugins installieren, aktivieren, pro Regel eine Instanz anlegen. Der vollständige Ablauf mit Abbruchpunkten, Smoke-Test und Notausschalter steht in [docs/INSTALL.de.md](docs/INSTALL.de.md).

## Wie Regeln funktionieren

**Eine Plugin-Instanz ist eine Regel.** Admin-Panel, Verwalten, Plugins, Ticket Automation, Instances, Add New Instance. Der Instanzname ist der Regelname, der Instanzstatus (aktiviert oder deaktiviert) schaltet die Regel ein und aus.

Warum Instanzen und kein eigener Regel-Editor: osTicket speichert, prüft und schützt Instanz-Einstellungen bereits, und nur Admins kommen auf diese Seite. Ein eigener Editor hieße ein neuer Admin-Endpunkt mit eigener CSRF- und Rechteprüfung, ohne dass eine Regel dadurch mehr ausdrücken könnte.

### Reihenfolge

Regeln laufen in Instanz-Reihenfolge, also in der Reihenfolge ihrer Anlage. **Pro Ereignis läuft nur die erste passende Regel.** Stellt Regel A ein Ticket auf „Offen“ und Regel B beobachtet „Offen“, löst eine Kundennachricht nur A aus. Um die Reihenfolge zu ändern, eine Instanz neu anlegen.

### Auslöser

| Auslöser | Reagiert auf | Standard |
|---|---|---|
| Nachricht vom Kunden | Neue Nachricht (`M`), Absender ist der Ticket-Benutzer | an |
| Nachricht von einem Mitleser | Neue Nachricht von jemand anderem im Verlauf | aus |
| Agenten-Antwort | Antwort (`R`) eines Agenten | aus |
| Interne Notiz eines Agenten | Notiz (`N`) eines Agenten. Systemnotizen zählen nie | aus |
| Fälligkeit erreicht | Bei jedem Cron-Lauf: Tickets, deren Fälligkeitsdatum vorbei ist | aus |

Die Nachricht, mit der ein Ticket eröffnet wird, ist seine Anlage, keine Antwort, und löst nie eine Regel aus.

„Due date to check“: nur das am Ticket gesetzte Fälligkeitsdatum (Standard) oder dieses und, wenn keins gesetzt ist, das SLA-Fälligkeitsdatum.

### Bedingungen

Alle müssen zutreffen. Leere Listen heißen „beliebig“.

| Bedingung | |
|---|---|
| Aktueller Status | Pflicht, einer oder mehrere. Nur offene Status, siehe [Bekannte Grenzen](#bekannte-grenzen) |
| Abteilung | Eine der gewählten |
| Hilfethema | Eines der gewählten |
| Nur zugewiesene Tickets | Einem Agenten oder Team zugewiesen |
| Nur passiv zugeordnete Mail | Die Regel läuft nur für Mails, die osTicket passiv zugeordnet hat, siehe unten. Erlaubt geschlossene Status als Bedingung |
| Bounces und Auto-Replies ignorieren | Standard an. Abwesenheitsnotizen und Unzustellbarkeitsmeldungen zählen nicht als Nachricht. Erkannt an den Mail-Headern mit osTickets eigener Prüfung (`ThreadEntry::isBounceOrAutoReply()`) |

### Aktionen

| Aktion | |
|---|---|
| Status setzen | Nur aktivierte Status. Ein geschlossener Status nur mit dem Fälligkeits-Auslöser |
| Priorität setzen | Über dasselbe Feldformular wie osTickets Dialog „Feld bearbeiten“ |
| Fälligkeitsdatum | Behalten, leeren oder auf jetzt plus 1 bis 365 Tage setzen |
| Als unbeantwortet markieren | |
| Agenten bei passiv zugeordneter Mail benachrichtigen | Schickt osTickets Benachrichtigung „Neue Nachricht“, wenn osTicket still geblieben ist, siehe unten. Nur Nachrichten von Kunde und Beteiligten |
| Interne Notiz | Wird immer geschrieben. Vorlage mit Platzhaltern, siehe unten |

Es gibt keine Aktion, die dem Kunden mailt oder etwas löscht.

#### Passiv zugeordnete Mail

osTicket ordnet eine eingehende Mail einem Ticket über einen Bezug auf eine Mail des Helpdesks zu oder über die Ticketnummer im Betreff. Ein dritter Weg ist das „passive Threading“: Die Mail bezieht sich nur auf eine frühere Nachricht des Tickets, typischerweise wenn ein Kunde seine eigene erste Mail weiterleitet, statt zu antworten. osTicket nimmt eine solche Mail ins Ticket auf, schickt den Agenten aber **keine** Benachrichtigung „Neue Nachricht“ (`ThreadEntry::postEmail()` übergibt `autorespond=false`, `Ticket::postMessage()` endet vor der Benachrichtigung). Die Nachricht ist da, niemand erfährt davon.

Mit „Agenten bei passiv zugeordneter Mail benachrichtigen“ schickt die Regel diese Benachrichtigung selbst: mit osTickets Vorlage, Absenderadresse und Empfänger-Einstellungen (letzter Bearbeiter, zuständiger Agent oder Team, Abteilungsleitung, Account-Manager). Positiv zugeordnete Mails bleiben unberührt, für sie benachrichtigt osTicket schon selbst. Unzustellbarkeits- und Abwesenheitsmeldungen benachrichtigen nie. Die interne Notiz nennt die Zahl der benachrichtigten Agenten; konnte niemand benachrichtigt werden, steht der Grund im Systemlog.

Die Benachrichtigung geht nur raus, wenn die Regel läuft, also entscheiden ihre Bedingungen: Eine Regel nur für „Warten“-Status benachrichtigt nur bei Tickets in diesen Status.

Ein geschlossenes Ticket lässt osTicket bei einer solchen Mail außerdem geschlossen. Damit jede passiv zugeordnete Mail ankommt, eine letzte Regel anlegen: Bedingung **Only passively matched mail**, alle offenen und geschlossenen Status, „Set status“ auf einen offenen Status, Benachrichtigung an. Sie läuft für nichts anderes, normale Antworten bekommen also keine zusätzliche Notiz; für die benachrichtigt osTicket selbst und öffnet geschlossene Tickets selbst wieder.

| Kundenmail | Offenes Ticket | Geschlossenes Ticket |
|---|---|---|
| Normale Antwort | osTicket benachrichtigt | osTicket öffnet und benachrichtigt |
| Passiv zugeordnet | in osTicket still, die Regel benachrichtigt | in osTicket still und geschlossen, die Regel öffnet und benachrichtigt |

Mit dem Fälligkeits-Auslöser muss die Regel ihre eigene Bedingung beenden: ein Zielstatus, der nicht unter den aktuellen Status ist, oder das Fälligkeitsdatum leeren oder verschieben. Sonst griffe der nächste Cron-Lauf dasselbe Ticket wieder; eine solche Regel lässt sich nicht speichern.

### Interne Notiz

Klartext, gepostet als SYSTEM, ohne Benachrichtigung. Platzhalter:

| Platzhalter | Wert |
|---|---|
| `{rule}` | Regelname |
| `{trigger}` | z. B. „a message from the customer“ |
| `{sender}` | Name des Absenders, „cron“ beim Fälligkeits-Auslöser |
| `{old_status}`, `{new_status}` | Status vorher und nachher |
| `{ticket_number}` | Ticketnummer |
| `{due_date}` | Fälligkeitsdatum vor dem Lauf, `-` wenn keins |
| `{changes}` | Was der Lauf geändert hat, z. B. `Status: Waiting -> Open; Due date cleared.`, und was nicht ging |

Leere Vorlage: `Rule "{rule}" ran on {trigger} from {sender}. {changes}`

Die Texte der Platzhalter `{trigger}` und `{changes}` sind englisch. Wer die Notiz deutsch will, schreibt die Vorlage deutsch und nutzt `{old_status}`, `{new_status}`, `{sender}`.

### Probelauf

Pro Regel. Ändert nichts und schreibt, was die Regel tun würde, nach Admin-Panel, Dashboard, System-Logs (Titel „Ticket Automation“). Auf einem Live-System vor dem Scharfschalten nutzen.

## Beispielregeln

Werden nicht automatisch angelegt. Statusnamen sind Beispiele.

**1. Kunde antwortet, während das Ticket auf ihn wartet**

| Einstellung | Wert |
|---|---|
| Message from the customer | an |
| Message from a collaborator | an, wenn Antworten von Mitlesern auch zählen sollen |
| Current status | Warten auf Kunde, Warten auf Dienstleister |
| Ignore bounces and auto-replies | an |
| Set status | Offen |
| Internal note | `{sender} hat geantwortet: {old_status} -> {new_status} (Regel „{rule}“).` |

**2. Wiedervorlage-Datum erreicht**

| Einstellung | Wert |
|---|---|
| Message from the customer | aus |
| Due date reached | an, „Due date set on the ticket only“ |
| Current status | Wiedervorlage |
| Set status | Offen |
| Due date | Clear (optional: das Datum hat seinen Zweck erfüllt) |
| Internal note | `Wiedervorlage {due_date} erreicht: {old_status} -> {new_status}.` |

## Sicherheit

- **Keine Schleifen.** Während eine Regel läuft, werden alle Verlaufseinträge, die sie erzeugt, ignoriert. Die eigene Notiz ist ein Systemeintrag und löst nie aus. Eine Regel läuft pro Ticket und Request höchstens einmal, ein Request führt höchstens 200 Regeln aus.
- **Keine Ketten.** Ein Ereignis, eine Regel.
- **Statuswechsel über osTicket.** `Ticket::setStatus()`, wie das Statusmenü. Regeln handeln als System, wie Auto-Cron: Keine Agentenrolle begrenzt den Wechsel, kein Agent wird schließender Agent.
- **Deaktivierte Status werden abgelehnt**, beim Speichern der Regel und vor jedem Lauf noch einmal, falls der Status später deaktiviert wurde. Ein abgelehnter Lauf ändert nichts und schreibt eine Warnung ins System-Log.
- **Fehler brechen osTicket nicht.** Ein Fehler in einer Regel wird abgefangen und geloggt; die Nachricht, Antwort oder der Cron-Lauf, der sie ausgelöst hat, läuft weiter.

### Notausschalter

1. Die Instanz der Regel oder das ganze Plugin im Admin-Panel deaktivieren.
2. Ohne Admin-Panel: leere Datei `include/plugins/ticket-automation/DISABLED` anlegen. Ab dem nächsten Request läuft keine Regel mehr. Datei löschen, um wieder einzuschalten.

## Einstellungen

| Einstellung | Standard | Hinweis |
|---|---|---|
| Dry run | aus | nur loggen |
| Message from the customer | an | |
| Message from a collaborator | aus | |
| Agent reply | aus | |
| Internal note by an agent | aus | |
| Due date reached | aus | braucht Cron |
| Due date to check | Ticket-Fälligkeit | oder Ticket-Fälligkeit, sonst SLA |
| Current status | keiner | Pflicht, offene Status |
| Department | beliebig | |
| Help topic | beliebig | |
| Only assigned tickets | aus | |
| Only passively matched mail | aus | nur Auslöser Kunde oder Beteiligte |
| Ignore bounces and auto-replies | an | nur Nachrichten-Auslöser |
| Set status | behalten | |
| Set priority | behalten | |
| Due date | behalten | behalten, leeren, setzen |
| Days | 3 | 1 bis 365, mit „Set“ |
| Mark unanswered | aus | osTicket macht das bei Kundennachrichten selbst |
| Alert agents on passively matched mail | aus | Auslöser Kunde oder Beteiligte nötig |
| Internal note | Standardtext | max. 2000 Zeichen |

## Bekannte Grenzen

- **Nur offene Status als Bedingung**, außer die Regel läuft nur für passiv zugeordnete Mails. Bei jeder anderen Kundennachricht öffnet osTicket ein geschlossenes Ticket selbst wieder, direkt nachdem die Regel gelaufen ist; eine Regel auf geschlossene Tickets liefe dagegen an.
- **Nachrichten-Auslöser können keinen geschlossenen Status setzen**, aus demselben Grund: osTicket würde das Ticket sofort wieder öffnen.
- **Der Fälligkeits-Auslöser läuft mit Cron.** Nur mit Auto-Cron läuft er, solange Agenten das Panel offen haben (höchstens alle 3 Minuten pro Agent). Die Verzögerung beträgt bis zu ein Cron-Intervall.
- **Bis zu 100 fällige Tickets pro Regel und Cron-Lauf**, älteste Fälligkeit zuerst; der Rest folgt im nächsten Lauf.
- **Agenten-Antwort und -Notiz als Auslöser:** Einen Status, den der Agent im Antwort- oder Notizformular wählt, setzt osTicket nach der Regel; die Wahl des Agenten gewinnt.
- **Nachrichten an ein zusammengeführtes Unterticket** landen im Verlauf des Eltern-Tickets (osTicket-Verhalten); die Regel sieht das Eltern-Ticket.
- **Ein Speichern, das die Regelprüfung ablehnt, kann beim Neuanlegen eine leere, deaktivierte Instanz hinterlassen** (osTicket legt die Instanz an, bevor es die Einstellungen speichert). Löschen oder die Einstellungen korrigieren.
- **Passiv zugeordnete Mails werden so erkannt, wie osTicket 1.18.4 zuordnet** (Bezüge auf vom Helpdesk erzeugte Mails, dann auf beliebige gespeicherte Nachrichten). Ändert ein künftiges osTicket diese Logik, muss das Plugin geprüft werden.
- **Kein Umsortieren.** Instanzen neu anlegen, um die Reihenfolge zu ändern.
- Nicht getestet: osTicket 1.17 und älter, MySQL statt MariaDB, Mailabruf über IMAP/POP (der Pipe-Weg `api/pipe.php` ist getestet; beide enden in derselben `Ticket::postMessage()`).

## Tests

`php tests/unit.php` läuft ohne osTicket. `./dev/up.sh && ./dev/test.sh` fährt die Integrations-Suite gegen ein echtes osTicket 1.18.4 in Docker. Details: [tests/README.md](tests/README.md).

## Lizenz

GPL-2.0-or-later, wie osTicket. Siehe [LICENSE](LICENSE).
