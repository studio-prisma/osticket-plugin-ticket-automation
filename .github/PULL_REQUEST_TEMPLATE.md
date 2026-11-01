## What changes

<!-- One or two sentences. What does this PR do, and why? -->

## Type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] CI / tooling
- [ ] Refactor (no behaviour change)

## Safety impact

- [ ] Changes which events trigger a rule (`TicketAutomationMatcher::classify`, the signals)
- [ ] Changes what a rule may do or which statuses it may set (`TicketAutomationRule::validate`)
- [ ] Changes the loop protection or the order of rules
- [ ] Touches the due-date query (`TicketAutomationMatcher::dueSql`)
- [ ] No safety-relevant surface touched

If any box above is checked, describe what can now happen to a ticket that couldn't before:

<!-- ... -->

## Verification

- [ ] `php tests/unit.php` passes (PHP version: ______)
- [ ] `./dev/test.sh` passes against `./dev/up.sh`
- [ ] No hardcoded hostnames, credentials or absolute paths added

## Related issues

Closes #
