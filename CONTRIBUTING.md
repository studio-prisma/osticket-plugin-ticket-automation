# Contributing

This plugin changes helpdesk tickets without anyone clicking. The bar for changes is verification, not volume.

## Before you open a pull request

1. **Open an issue first** for anything beyond a typo or a documentation fix.
2. **Changes to triggers, checks or loop protection need a note in the PR**: what can now happen to a ticket that couldn't before, and why that's acceptable.
3. **Never report a vulnerability as a pull request.** See [SECURITY.md](SECURITY.md).

## Development setup

No build step and no PHP dependencies.

```bash
git clone https://github.com/studio-prisma/osticket-plugin-ticket-automation.git
cd osticket-plugin-ticket-automation
php tests/unit.php
```

For the integration suite you need Docker:

```bash
./dev/up.sh       # osTicket 1.18.4 + MariaDB + Mailpit on 127.0.0.1
./dev/test.sh     # unit tests (local and container PHP) + integration suite
./dev/down.sh
```

To build the install package (needs `zip`):

```bash
./tools/build-zip.sh                # writes dist/ticket-automation-<version>.zip
```

## What a change has to pass

| Check | Command |
|---|---|
| Syntax, all PHP files | `find . -name '*.php' -not -path './dev/local/*' -exec php -l {} \;` |
| Unit tests | `php tests/unit.php` |
| Integration | `./dev/test.sh` against `./dev/up.sh` |

CI runs syntax and unit tests on PHP 8.0 to 8.4, plus the integration suite, on every push and pull request. A red pipeline isn't mergeable.

## Code conventions

- **PHP 8.0 compatible.** No `match`, no enums, no readonly properties, no named arguments.
- **No runtime dependencies.**
- **Pure core, thin edge.** Deciding what runs lives in `TicketAutomationRule` and `TicketAutomationMatcher`, without osTicket classes, and gets unit tests. `TicketAutomationRunner` is the only class that touches tickets.
- **Changes through osTicket's methods.** `Ticket::setStatus()`, `Ticket::updateField()`, `Ticket::logNote()`. The one direct write is the due date (`duedate`, `isoverdue`), saved through the ticket model.
- **Nothing in bootstrap.** `bootstrap()` reads settings and connects signals; osTicket's config and staff session aren't complete yet.
- **No hardcoded hostnames, credentials, absolute paths or customer data**, tests included.
- **Every new setting** needs a key in `TicketAutomationRule::CONFIG_KEYS`, a check in `validate()` if it can be wrong, a unit test, and a row in the settings table of both READMEs.

## Documentation

User-facing changes need updates in `README.md`, `README.de.md`, `docs/INSTALL.md` and `docs/INSTALL.de.md`, plus a `CHANGELOG.md` entry under `## [Unreleased]`.

## Commit messages

Conventional Commits, imperative mood:

```
fix(runner): ignore entries while a rule is running
feat(rule): add a help topic condition
docs(install): add the dry-run step
```

## Release process

1. Move the `## [Unreleased]` entries into a new `## [x.y.z] - YYYY-MM-DD` section.
2. Bump `'version'` in `plugin.php` and `TicketAutomationPlugin::VERSION` to the same number.
3. Commit, then tag: `git tag -a vx.y.z -m "vx.y.z"` and push the tag.
4. The `Release` workflow builds `dist/ticket-automation-x.y.z.zip`, attests it, extracts the changelog section and publishes the release.

## License

By contributing you agree that your contribution is licensed under the [GNU General Public License, version 2 or later](LICENSE).
