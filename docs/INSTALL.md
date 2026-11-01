# Installation

For osTicket 1.18.x. Every step has a checkpoint; stop there if it fails.

**Read first:** a rule changes tickets without anyone clicking. The kill switch (step 0) works without the admin panel. Every new rule starts in dry-run mode (step 5) before it changes anything.

## 0. Kill switch, before anything else

Two ways to stop every rule:

- Admin Panel, Manage, Plugins: disable "Ticket Automation" (or a single instance).
- On the server: `touch include/plugins/ticket-automation/DISABLED`. Effective with the next request; delete the file to switch back on.

## 1. Check the environment

- osTicket version under Admin Panel, Dashboard, Information: 1.18.x.
- PHP 8.0 or newer.
- For the due-date trigger: is `api/cron.php` called by a cron job, or is auto-cron enabled (Admin Panel, Settings, System)? Without either, the due-date trigger never runs.

**Stop** if osTicket is older than 1.18.

## 2. Copy the files

```bash
cd /path/to/osticket/include/plugins
unzip ticket-automation-<version>.zip      # creates ticket-automation/
chown -R <web-user>: ticket-automation
```

Checkpoint: `include/plugins/ticket-automation/plugin.php` exists and is readable by the web server user. There is no `DISABLED` file in the folder.

## 3. Install and enable the plugin

Admin Panel, Manage, Plugins, Add New Plugin, "Ticket Automation", Install. Then open it and set Status to Active.

Checkpoint: the plugin is listed as active. Nothing changes yet; without an instance there is no rule.

## 4. Find the status IDs and names

Admin Panel, Manage, Lists, Ticket Status. Note which statuses are open, which are enabled, and which ones your rules should watch and set. Disabled statuses can't be set by a rule.

## 5. First rule, in dry-run mode

Plugin, Instances, Add New Instance:

1. Name: what the rule does, e.g. "Customer replied". This name shows up in every note.
2. Status: Enabled.
3. **Dry run: on.**
4. Trigger, conditions, actions as in the [README examples](../README.md#example-rules).
5. Save.

Checkpoint: the instance is saved without an error message. An error names the setting to fix (e.g. a disabled target status, or a due-date rule that wouldn't move the ticket on). If an error appears while adding, osTicket may already have created an empty, disabled instance: delete it before trying again.

## 6. Smoke test

On a test ticket in a watched status:

| Case | Expected |
|---|---|
| Reply as the customer (portal or mail) | System log (Admin Panel, Dashboard, System Logs) has an entry "Dry run: rule ... would run on ticket #..." with the planned changes. The ticket is unchanged |
| Agent reply on the same ticket | No new entry (unless the rule watches agent replies) |
| Due-date rule: set the due date in the past, wait for cron | Dry-run entry for that ticket |

Then switch Dry run off and repeat the first case:

| Case | Expected |
|---|---|
| Reply as the customer | Status changes as configured; one internal note "Ticket Automation" by SYSTEM; no mail to the customer from the plugin |
| Out-of-office reply by mail | No change (with "Ignore bounces and auto-replies" on) |

**Stop** and disable the instance if a rule changes a ticket it shouldn't.

## 7. Security gate

| Check | Expected |
|---|---|
| A customer replies twice in a row | One status change, one note per message, no growing chain of notes |
| An agent writes an internal note on a ticket a note-triggered rule watches | Exactly one automation note per agent note |
| Disable the target status in the status list, then trigger the rule | Ticket unchanged; system log warning "refused ... disabled in the status list" |
| Create `DISABLED` in the plugin folder, trigger the rule | Ticket unchanged, no log entry. Delete the file afterwards |
| Mailbox of the test customer | No mail from the plugin. Mails osTicket sends on its own (auto-responses, collaborator notices) are unchanged by the plugin |

## Updating

Replace the folder contents, keep the instances. Read the CHANGELOG for setting changes first.

## Uninstalling

Disable the plugin, delete its instances, uninstall it under Plugins, remove the folder. Notes already written stay in the tickets.
