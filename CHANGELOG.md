# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-11-23

### Changed

- A run that changes nothing writes no internal note, for every trigger, not only the due date. Before, a message on a ticket the actions had nothing left to do for still got a "No change needed." note. If osTicket refused an action, the reason goes to the system log instead. A rule whose only action is the note still writes it on every run; a partial run still writes its note and lists what was not applied.

### Fixed

- Due-date rules no longer loop. A cron run that changes nothing, because osTicket refuses the target status (closing with open tasks, missing required fields or help topic) or nothing was left to change, writes no internal note: it logs one warning with the reason and leaves that ticket alone for that rule until the ticket or the rule changes, at the latest for 24 hours. Before, every cron call wrote the same note again, thousands a day with auto-cron. Held-back tickets no longer take places among the 100 tickets per rule and run.
- "Clear the due date" together with "Due date, or the SLA due date if none is set" is refused on save: after clearing, the SLA due date is still due and the rule ran again on every cron call. Such a rule needs a target status outside the condition, or "postpone" instead. Rules already stored that way stop running and are logged once a day until fixed.
- A due-date rule in dry run logs its plan once per ticket and day instead of on every cron call.

## [0.3.0] - 2026-11-18

### Added

- Condition "Only passively matched mail": the rule runs only for mail osTicket added to the ticket passively. With it, closed statuses can be a condition, because osTicket does not reopen a ticket for such a mail. A last rule with this condition, all open and closed statuses, an open target status and the alert catches every passively matched mail without notes on ordinary replies.

### Changed

- Whether a message was matched passively is checked once per message, before the rules are picked, and only when a rule uses the condition or the alert.

## [0.2.0] - 2026-11-18

### Added

- Action "Alert agents on passively matched mail": osTicket adds a mail that only refers to an earlier message of the ticket (a forward of the customer's own first mail, for example) "passively" and sends agents no "New Message" alert. With the action on, the rule sends that alert with osTicket's template and recipient settings. Positively matched mail, bounces and auto-replies are left alone. Off by default.

### Changed

- `LICENSE` contains only the GPL-2.0 text, so GitHub detects the licence.
  Removed a header that wrongly claimed earlier releases were MIT-licensed.

## [0.1.0] - 2026-11-01

### Added

- Rules as plugin instances: one instance is one rule with trigger, conditions and actions, enabled and disabled through the instance status.
- Triggers: message from the customer (on by default), message from a collaborator, agent reply, internal note by an agent, due date reached (cron; ticket due date, optionally the SLA due date).
- Conditions: current status (required, open statuses), department, help topic, only assigned tickets, ignore bounces and auto-replies (on by default, osTicket's header checks).
- Actions: set status (enabled statuses; closed only with the due-date trigger), set priority, clear or postpone the due date, mark unanswered, internal note with placeholders. Every run writes the note as SYSTEM, without alert.
- Dry run per rule: logs the planned changes to the system log, changes nothing.
- Loop protection: entries created while a rule runs are ignored, system entries never trigger, one rule per ticket per request, at most 200 runs per request, one rule per event.
- Checks on save and before every run: disabled or missing target status, closed target with a message trigger, due-date rules that would not end their own condition.
- Kill switch: a `DISABLED` file in the plugin folder stops every rule.
- Unit tests without osTicket; integration suite against osTicket 1.18.4 in Docker, including mail piped through `api/pipe.php` and `api/cron.php`.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/releases/tag/v0.1.0
