# Ticket Automation for osTicket

Rules that change tickets when something happens: a customer replies, a collaborator writes, an agent replies or adds a note, or a due date passes. Each rule has a trigger, conditions and actions. Every run leaves an internal note in the ticket.

Typical use: a ticket waits for the customer; the customer answers; the ticket goes back to "Open" without anyone touching it.

[Deutsche Fassung](README.de.md)

## Requirements

- osTicket 1.18.x (tested: 1.18.4)
- PHP 8.0 or newer (tested: 8.3, 8.4)
- For the due-date trigger: osTicket's cron (`api/cron.php` from a cron job) or auto-cron

## Installation

Short version: unzip into `include/plugins/ticket-automation/`, install under Admin Panel, Manage, Plugins, enable, add one instance per rule. The full procedure with checkpoints, smoke test and kill switch is in [docs/INSTALL.md](docs/INSTALL.md).

## How rules work

**One plugin instance is one rule.** Admin Panel, Manage, Plugins, Ticket Automation, Instances, Add New Instance. The instance name is the rule name; the instance status (enabled or disabled) switches the rule on and off.

Why instances and not a rule editor of its own: osTicket already stores, validates and permission-checks instance settings, and only admins reach that page. A separate editor would mean a new admin endpoint with its own CSRF and permission handling, for no gain in what a rule can express.

### Order

Rules run in instance order, which is creation order. **Per event only the first matching rule runs.** If rule A moves a ticket to "Open" and rule B watches "Open", one customer message runs A only. To change the order, recreate an instance.

### Triggers

| Trigger | Fires on | Default |
|---|---|---|
| Message from the customer | A new message (`M`) whose sender is the ticket's user | on |
| Message from a collaborator | A new message from anyone else on the thread | off |
| Agent reply | A response (`R`) by an agent | off |
| Internal note by an agent | A note (`N`) by an agent. System notes never count | off |
| Due date reached | On every cron run: tickets whose due date has passed | off |

The message that opens a ticket is its creation, not a reply, and never triggers a rule.

"Due date to check": the due date set on the ticket only (default), or that date and, if none is set, the SLA due date.

### Conditions

All must hold. Empty lists mean "any".

| Condition | |
|---|---|
| Current status | Required, one or more. Open statuses only, see [Known limits](#known-limits) |
| Department | Any of the selected |
| Help topic | Any of the selected |
| Only assigned tickets | Assigned to an agent or a team |
| Ignore bounces and auto-replies | Default on. Out-of-office replies and delivery failures don't count as a message. Detected from the mail headers with osTicket's own checks (`ThreadEntry::isBounceOrAutoReply()`) |

### Actions

| Action | |
|---|---|
| Set status | Enabled statuses only. A closed status only with the due-date trigger |
| Set priority | Through the same field form as osTicket's "edit field" dialog |
| Due date | Keep, clear, or set to now plus 1 to 365 days |
| Mark unanswered | |
| Internal note | Always written. Template with placeholders, see below |

There are no actions that mail the customer or delete anything.

With the due-date trigger the rule has to end its own condition: a target status that isn't among the current statuses, or clearing or postponing the due date. Otherwise the next cron run would pick the same ticket again; such a rule can't be saved.

### Internal note

Plain text, posted as SYSTEM, without alert. Placeholders:

| Placeholder | Value |
|---|---|
| `{rule}` | Rule name |
| `{trigger}` | e.g. "a message from the customer" |
| `{sender}` | Name of the sender; "cron" for the due-date trigger |
| `{old_status}`, `{new_status}` | Status before and after |
| `{ticket_number}` | Ticket number |
| `{due_date}` | Due date before the run, `-` if none |
| `{changes}` | What the run changed, e.g. `Status: Waiting -> Open; Due date cleared.` and what it couldn't |

Empty template: `Rule "{rule}" ran on {trigger} from {sender}. {changes}`

### Dry run

Per rule. Changes nothing and writes what the rule would do to Admin Panel, Dashboard, System Logs (title "Ticket Automation"). Use it on a live system before switching a rule on.

## Example rules

Not created automatically. Status names are examples; use yours.

**1. Customer answers while the ticket waits for them**

| Setting | Value |
|---|---|
| Message from the customer | on |
| Message from a collaborator | on, if replies from CCs should count too |
| Current status | Waiting for customer, Waiting for vendor |
| Ignore bounces and auto-replies | on |
| Set status | Open |
| Internal note | `{sender} replied: {old_status} -> {new_status} (rule "{rule}").` |

**2. Resubmission date reached**

| Setting | Value |
|---|---|
| Message from the customer | off |
| Due date reached | on, "Due date set on the ticket only" |
| Current status | Resubmission |
| Set status | Open |
| Due date | Clear (optional: the date has done its job) |
| Internal note | `Resubmission date {due_date} reached: {old_status} -> {new_status}.` |

## Safety

- **No loops.** While a rule runs, every thread entry it causes is ignored. The rule's own note is a system entry, which never triggers. A rule runs at most once per ticket per request, and a request runs at most 200 rules.
- **No chains.** One event, one rule.
- **Status changes through osTicket.** `Ticket::setStatus()`, as the status menu does. Rules act as the system, like auto-cron: no agent's role limits the change, and no agent becomes closing agent.
- **Disabled statuses are refused** when the rule is saved and again before every run, in case the status was disabled later. A refused run changes nothing and writes a warning to the system log.
- **Failures don't break osTicket.** An error inside a rule is caught and logged; the message, reply or cron run that triggered it goes on.

### Kill switch

1. Disable the rule's instance, or the whole plugin, in the admin panel.
2. Without the admin panel: create an empty file `include/plugins/ticket-automation/DISABLED`. From the next request on, no rule runs. Delete it to switch back on.

## Configuration reference

| Setting | Default | Notes |
|---|---|---|
| Dry run | off | Log only |
| Message from the customer | on | |
| Message from a collaborator | off | |
| Agent reply | off | |
| Internal note by an agent | off | |
| Due date reached | off | Needs cron |
| Due date to check | ticket due date | or ticket due date, else SLA due date |
| Current status | none | required, open statuses |
| Department | any | |
| Help topic | any | |
| Only assigned tickets | off | |
| Ignore bounces and auto-replies | on | message triggers only |
| Set status | keep | |
| Set priority | keep | |
| Due date | keep | keep, clear, set |
| Days | 3 | 1 to 365, with "set" |
| Mark unanswered | off | osTicket does it itself on customer messages |
| Internal note | default text | max. 2000 characters |

## Known limits

- **Only open statuses as condition.** On a customer message osTicket reopens a closed ticket itself, right after the rule has run. A rule on closed tickets would race against that.
- **Message triggers can't set a closed status**, for the same reason: osTicket would reopen the ticket immediately.
- **The due-date trigger runs with cron.** With auto-cron only, it runs while agents have the panel open (at most every 3 minutes per agent). The delay is up to one cron interval.
- **Up to 100 due tickets per rule per cron run**, oldest due date first; the rest follows on the next run.
- **Agent reply and note triggers:** a status the agent picks in the reply or note form is set by osTicket after the rule ran, so the agent's choice wins.
- **Messages to a merged child ticket** land in the parent's thread (osTicket behaviour); the rule sees the parent.
- **A save that the rule checks refuse can leave an empty, disabled instance** behind when adding a new one (osTicket creates the instance before saving its settings). Delete it or fix the settings.
- **No reordering.** Recreate instances to change the order.
- Not tested: osTicket 1.17 and older, MySQL instead of MariaDB, mail fetched over IMAP/POP (the piped mail path `api/pipe.php` is tested; both end in the same `Ticket::postMessage()`).

## Tests

`php tests/unit.php` runs without osTicket. `./dev/up.sh && ./dev/test.sh` runs the integration suite against a real osTicket 1.18.4 in Docker. Details: [tests/README.md](tests/README.md).

## License

GPL-2.0-or-later, as osTicket. See [LICENSE](LICENSE).
