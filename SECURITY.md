# Security Policy

## Supported Versions

Only the latest minor release receives security fixes.

| Version | Supported |
|---|---|
| `0.1.x` | yes |
| everything older | no |

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-plugin-ticket-automation/security/advisories/new)**. Reports there are private by default. Don't open a public issue for a suspected vulnerability.

Include:

- plugin version and osTicket version
- what an attacker gains, not only what misbehaves
- reproduction steps, ideally against a clean install (`dev/up.sh` builds one)

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project.

## In Scope

- **Anyone but an admin creating or changing a rule**, or a rule being changed without a valid CSRF token.
- **A rule running on an event it isn't configured for**, e.g. a collaborator message on a rule with that trigger off, the opening message of a new ticket, a bounce or auto-reply with the ignore setting on, or the plugin's own note.
- **A rule setting a status the checks refuse:** disabled, deleted, archived, or closed from a message trigger.
- **A loop or chain:** one event running more than one rule, or a rule running more than once per ticket per request.
- **Mail to a customer** caused by the plugin itself, or an alert from the plugin's note.
- **Markup or script from a sender name, ticket data or the note template** rendered as HTML in the ticket view.
- **SQL injection** through rule settings into the due-date query.
- **A failing rule breaking** the request that posted the message, reply or note, or the cron run.

## Out of Scope

Documented design decisions are not findings.

- **Rules act as the system.** An agent whose reply triggers a rule doesn't need the permission for what the rule does; the admin who configured it decided that. Same as osTicket's own auto-cron.
- **Status changes and notes are visible to agents** who can see the ticket. That's the purpose.
- **Mails osTicket sends on its own** for the same event (auto-responses, collaborator notices, new-message alerts). The plugin doesn't add, suppress or change them.
- **Bounce and auto-reply detection** is osTicket's (`TicketFilter::isBounce()`, `isAutoReply()`). Mails that slip past it are an upstream topic.
- Anything that requires admin access to osTicket or write access to the server.
- **Bugs in osTicket itself.** Report those [upstream](https://github.com/osTicket/osTicket/security).
- Automated scanner output without a working proof of concept.

## Handling of Secrets

The plugin stores no credentials and calls no external service. The test stack generates its passwords at runtime into `dev/local/` (git-ignored). A secret that reaches a commit anyway is rotated first and removed second.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
