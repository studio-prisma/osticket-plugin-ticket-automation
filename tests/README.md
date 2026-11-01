# Tests

## Unit, no osTicket required

```bash
php tests/unit.php
```

| Area | What is checked |
|---|---|
| `TicketAutomationRule::normalise` | defaults (only the customer trigger on, bounces ignored, nothing changes), every shape a choice value arrives in (multiselect, JSON, single key, list, comma list), junk and negative IDs dropped, clamps for days and note length, every config field read at bootstrap |
| `TicketAutomationRule::validate` | status condition required and open only, disabled/unknown/archived target refused, closed target only with the due-date trigger, due-date rules must end their own condition, day range, note length |
| `TicketAutomationMatcher` | entry classification (customer, collaborator, agent reply, agent note, system entries, the opening message), matching on status, state, department, topic, assignment, bounces; first rule wins; the due-date SQL (sources, filters, limit, integers only); the note template |
| `TicketAutomationRunner` against stand-in tickets | status change and note, bounce, agent reply and note ignored by default, collaborator only when on, opening message without and with flag, loop (entry created during a run, same rule twice, system note), chain of two rules, target disabled after saving, osTicket refusing the status, dry run, agent context restored, exception caught, due-date clear and unanswered, cron selection by rule and due-date source, a moved ticket not picked again |
| Bootstrap | one rule per instance, HTML entities in the name decoded, signals connected once, the `DISABLED` kill switch (skipped where the plugin folder is read-only) |

`stubs/` holds stand-ins for the osTicket classes the plugin files require. `PLUGIN_DIR` overrides the plugin location, default is the repository root.

## Integration, real osTicket

```bash
./dev/up.sh
./dev/test.sh
```

`dev/test.sh` runs the unit tests with the local PHP and the container's PHP 8.3, then `tests/integration/run.php` inside the container. The suite writes rules into the plugin's instance tables as the admin panel stores them. Every event runs in its own PHP process (`step.php`, or osTicket's `api/pipe.php` and `api/cron.php`), so the plugin bootstraps its rules from the database as in a real request.

How mail is simulated: `Ticket::postMessage()` with the sender's user ID, as the portal and the mail fetcher call it; with raw headers and a Message-Id for bounces and auto-replies; and raw RFC 822 messages piped into `api/pipe.php`, which parses them and matches the ticket by `[#number]` in the subject.

| Case | Expected |
|---|---|
| Customer message on "Waiting for customer" / "Waiting for vendor" | Open, one note by SYSTEM with the filled template, ticket unanswered, no mail |
| Customer message on another status | nothing |
| Customer message on a closed ticket | osTicket reopens it, no rule |
| Customer reply by mail through `api/pipe.php` | Open |
| Auto-reply by mail through `api/pipe.php` | nothing |
| Agent reply, agent note | nothing |
| Auto-reply and bounce headers | nothing |
| Plain mail headers | Open |
| Collaborator message | nothing with the trigger off, Open with it on |
| Cron: due resubmission | Open with note, no mail; second cron run adds nothing |
| Cron: not yet due, no due date, due in another status | nothing |
| Cron with "clear due date" | due date and overdue flag gone |
| Admin save with a disabled target / closed target on a message trigger | refused with the reason, nothing stored |
| Admin save of a valid rule | stored |
| Target disabled after saving | nothing, warning in the system log |
| Priority and due-date actions | priority via the field form, due date two days ahead, note lists the changes |
| New ticket in a watched status | its opening message runs no rule; the first reply does |
| Two rules that would ping-pong | one message, one rule |
| Rule on agent notes and replies | one automation note per agent entry, no cascade |
| Dry run | nothing changes, plan in the system log |
| Disabled instance | nothing |

## Not covered

- Mail fetched over IMAP/POP (ends in the same `Ticket::postMessage()` as the pipe)
- osTicket 1.17, MySQL
- The admin panel UI in a browser (the save path is exercised through `PluginInstance::update()`)
