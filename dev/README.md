# Local test stack

osTicket 1.18.4 on PHP 8.3 / Apache, MariaDB 11.4 and Mailpit, all on `127.0.0.1`. For development only.

```bash
./dev/up.sh      # build, install, seed
./dev/test.sh    # unit tests + integration suite
./dev/down.sh    # stop and delete (the database lives on tmpfs)
```

`up.sh` generates all passwords on the first run into `dev/local/credentials.env`. `dev/local/` is git-ignored. The seed writes status, user and agent IDs to `dev/local/seed.json`, which the integration suite reads.

| URL | What |
|---|---|
| `http://127.0.0.1:8091/` | Client portal |
| `http://127.0.0.1:8091/scp/` | Agent panel; the plugin is under Admin Panel, Manage, Plugins |
| `http://127.0.0.1:8029/` | Mailpit: every mail osTicket sends |

The repository root is mounted read-only as `include/plugins/ticket-automation`, `tests/` as `/tests`. Edits show up without a rebuild.

## Test data

`seed/seed.php` adds the statuses "Waiting for customer", "Waiting for vendor", "Resubmission" (open, enabled) and "Parked" (open, disabled), the users alice@example.com (ticket owner) and bob@example.com (collaborator), and an agent. The plugin is installed and active without instances; the integration suite writes its own rules and removes them at the end.

Additional plugins or a theme can be mounted through `dev/local/compose.override.yml`; `up.sh` and `down.sh` pick it up.
