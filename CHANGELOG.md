# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-ticket-automation/releases/tag/v0.1.0
