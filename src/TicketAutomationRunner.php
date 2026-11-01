<?php

declare(strict_types=1);

/**
 * Runs rules on osTicket events. The only class here that touches tickets.
 *
 * Events:
 *   threadentry.created  include/class.thread.php, ThreadEntry::create(), after
 *                        the entry and its mail headers are saved. For a
 *                        customer message Ticket::postMessage() calls
 *                        onMessage() afterwards, which sets isanswered=0 and
 *                        reopens closed tickets. Neither undoes a rule:
 *                        conditions are open statuses only, message triggers
 *                        may only set open statuses, and the ORM saves dirty
 *                        fields only, so onMessage() never writes the status
 *                        back.
 *   cron                 include/class.cron.php (real cron) and
 *                        scp/autocron.php, both with no agent signed in.
 *
 * Loop protection, three layers:
 *   1. While a rule runs, every further thread entry is ignored ($running).
 *   2. System entries never trigger: the rule's own note is posted as SYSTEM,
 *      so it carries ThreadEntry::FLAG_SYSTEM (TicketAutomationMatcher::classify).
 *   3. A rule runs at most once per ticket per request, and a request runs at
 *      most MAX_RUNS rules in total.
 */
final class TicketAutomationRunner
{
    public const NOTE_TITLE = 'Ticket Automation';
    public const MAX_RUNS = 200;

    /** @var array<int, array> instance ID => normalised rule */
    private static $rules = array();

    /** @var bool */
    private static $running = false;

    /** @var array<string, true> "ticket:rule" already run in this request */
    private static $done = array();

    public static function register(array $rule): void
    {
        self::$rules[(int) $rule['id']] = $rule;
        ksort(self::$rules);
    }

    /** @return array<int, array> */
    public static function rules(): array
    {
        return array_values(self::$rules);
    }

    // ------------------------------------------------------------- signals

    public static function onThreadEntry($entry, $data = null): void
    {
        if (self::$running || !self::$rules || !($entry instanceof ThreadEntry)) {
            return;
        }
        try {
            self::handleEntry($entry);
        } catch (Throwable $e) {
            // Never break the core flow that posted the entry.
            self::$running = false;
            self::log('Rule run failed on a new thread entry: ' . get_class($e) . ': ' . $e->getMessage(), true);
        }
    }

    public static function onCron($object = null, $data = null): void
    {
        if (self::$running || !self::$rules) {
            return;
        }
        try {
            self::handleCron();
        } catch (Throwable $e) {
            self::$running = false;
            self::log('Due-date run failed: ' . get_class($e) . ': ' . $e->getMessage(), true);
        }
    }

    // ------------------------------------------------------------ handlers

    private static function handleEntry(ThreadEntry $entry): void
    {
        $thread = $entry->getThread();
        if (!$thread || $thread->getObjectType() !== 'T') {
            return;
        }
        $ticket = Ticket::lookup((int) $thread->getObjectId());
        if (!$ticket) {
            return;
        }

        $trigger = TicketAutomationMatcher::classify(array(
            'type'     => (string) $entry->getType(),
            'user_id'  => (int) $entry->get('user_id'),
            'staff_id' => (int) $entry->get('staff_id'),
            'owner_id' => (int) $ticket->getUserId(),
            'system'   => $entry->isSystem(),
            'original' => self::isOriginalMessage($entry, $thread),
        ));
        if ($trigger === null) {
            return;
        }
        // Only messages arrive by mail; header checks on agent entries
        // would be meaningless.
        $auto = $entry->getType() === 'M' && $entry->isBounceOrAutoReply();

        $facts = self::facts($ticket);
        $rule = TicketAutomationMatcher::pick(self::rules(), $trigger, $facts, $auto);
        if ($rule) {
            self::apply($rule, $ticket, $trigger, (string) $entry->getName(), $facts);
        }
    }

    private static function handleCron(): void
    {
        $dueRules = array();
        foreach (self::rules() as $rule) {
            if (!empty($rule['triggers']['due'])) {
                $dueRules[] = $rule;
            }
        }
        if (!$dueRules) {
            return;
        }

        // Ticket ID => rule IDs whose due-date query selected it.
        $candidates = array();
        foreach ($dueRules as $rule) {
            $res = db_query(TicketAutomationMatcher::dueSql($rule, TICKET_TABLE, TICKET_STATUS_TABLE));
            while ($res && ($row = db_fetch_row($res))) {
                $candidates[(int) $row[0]][(int) $rule['id']] = true;
            }
        }

        foreach ($candidates as $ticketId => $selectedBy) {
            $ticket = Ticket::lookup($ticketId);
            if (!$ticket) {
                continue;
            }
            $facts = self::facts($ticket);
            $eligible = array_values(array_filter($dueRules, static function ($r) use ($selectedBy) {
                return isset($selectedBy[(int) $r['id']]);
            }));
            $rule = TicketAutomationMatcher::pick($eligible, 'due', $facts);
            if ($rule) {
                self::apply($rule, $ticket, 'due', 'cron', $facts);
            }
        }
    }

    // --------------------------------------------------------------- apply

    private static function apply(array $rule, Ticket $ticket, string $trigger, string $sender, array $facts): void
    {
        $key = $ticket->getId() . ':' . $rule['id'];
        if (isset(self::$done[$key]) || count(self::$done) >= self::MAX_RUNS) {
            return;
        }
        self::$done[$key] = true;

        $statuses = TicketAutomationCatalog::statuses();
        $number = (string) $ticket->getNumber();
        $label = TicketAutomationMatcher::triggerLabel($trigger);

        // Checked again at run time: a status disabled or deleted after the
        // rule was saved stops the rule instead of being set anyway.
        $errors = TicketAutomationRule::validate($rule, $statuses);
        if ($errors) {
            self::log(sprintf('Rule "%s" refused on ticket #%s (%s): %s',
                $rule['name'], $number, $label, implode(' ', $errors)));
            return;
        }

        $oldStatus = $statuses[(int) $facts['status_id']]['name'] ?? (string) $facts['status_id'];
        $target = (int) $rule['set_status'];
        $newStatus = $target > 0 ? ($statuses[$target]['name'] ?? (string) $target) : $oldStatus;

        if ($rule['dry_run']) {
            self::log(sprintf('Dry run: rule "%s" would run on ticket #%s (%s from %s): %s',
                $rule['name'], $number, $label, $sender, implode('; ', self::plan($rule, $facts, $oldStatus, $newStatus))));
            return;
        }

        self::$running = true;
        // Automation acts as the system, like autocron does: no agent's role
        // limits or credits the change, and no agent becomes closing agent.
        $hadStaff = array_key_exists('thisstaff', $GLOBALS);
        $staff = $hadStaff ? $GLOBALS['thisstaff'] : null;
        $GLOBALS['thisstaff'] = null;
        $dueBefore = (string) ($ticket->getDueDate() ?: '');

        try {
            $done = array();
            $failed = array();

            if ($target > 0 && $target !== (int) $facts['status_id']) {
                $errs = array();
                if ($ticket->setStatus($target, '', $errs, false)) {
                    $done[] = sprintf('Status: %s -> %s', $oldStatus, $newStatus);
                } else {
                    $failed[] = 'status (' . (self::first($errs) ?: 'refused by osTicket') . ')';
                    $newStatus = $oldStatus;
                }
            }

            if ((int) $rule['set_priority'] > 0) {
                $r = self::setPriority($ticket, (int) $rule['set_priority']);
                if ($r === true) {
                    $done[] = 'Priority changed';
                } elseif ($r !== null) {
                    $failed[] = 'priority (' . $r . ')';
                }
            }

            if ($rule['due_action'] !== 'keep') {
                $r = self::changeDue($ticket, $rule['due_action'], (int) $rule['due_days']);
                if (is_string($r) && strpos($r, 'error:') === 0) {
                    $failed[] = 'due date (' . substr($r, 6) . ')';
                } elseif ($r !== null) {
                    $done[] = $r;
                }
            }

            if ($rule['mark_unanswered'] && $ticket->isAnswered()) {
                if ($ticket->markUnAnswered()) {
                    $done[] = 'Marked unanswered';
                } else {
                    $failed[] = 'mark unanswered';
                }
            }

            $changes = $done ? implode('; ', $done) . '.' : 'No change needed.';
            if ($failed) {
                $changes .= ' Not applied: ' . implode('; ', $failed) . '.';
            }
            $text = TicketAutomationMatcher::render($rule['note'], array(
                'rule'          => $rule['name'],
                'trigger'       => $label,
                'sender'        => $sender,
                'old_status'    => $oldStatus,
                'new_status'    => $newStatus,
                'ticket_number' => $number,
                'due_date'      => $dueBefore !== '' ? $dueBefore : '-',
                'changes'       => $changes,
            ));
            // A text body: osTicket escapes it on display, so nothing from a
            // sender name or the template turns into markup. Poster SYSTEM,
            // no alert: no mail to anyone, and the entry is a system entry.
            $ticket->logNote(self::NOTE_TITLE, new TextThreadEntryBody($text), 'SYSTEM', false);

            if ($failed) {
                self::log(sprintf('Rule "%s" on ticket #%s: not applied: %s', $rule['name'], $number, implode('; ', $failed)));
            }
        } finally {
            if ($hadStaff) {
                $GLOBALS['thisstaff'] = $staff;
            } else {
                unset($GLOBALS['thisstaff']);
            }
            self::$running = false;
        }
    }

    /** What a dry run reports. */
    private static function plan(array $rule, array $facts, string $old, string $new): array
    {
        $out = array();
        if ((int) $rule['set_status'] > 0 && (int) $rule['set_status'] !== (int) $facts['status_id']) {
            $out[] = "status $old -> $new";
        }
        if ((int) $rule['set_priority'] > 0) {
            $out[] = 'priority ' . (int) $rule['set_priority'];
        }
        if ($rule['due_action'] === 'clear') {
            $out[] = 'clear due date';
        } elseif ($rule['due_action'] === 'set') {
            $out[] = 'due date in ' . (int) $rule['due_days'] . ' days';
        }
        if ($rule['mark_unanswered']) {
            $out[] = 'mark unanswered';
        }
        $out[] = 'internal note';
        return $out;
    }

    /**
     * Same path as the core "edit field" dialog (TicketsAjaxAPI::editField):
     * the priority field's edit form, then Ticket::updateField().
     *
     * @return true|string|null  true changed, null nothing to do, string error
     */
    private static function setPriority(Ticket $ticket, int $priorityId)
    {
        $field = $ticket->getField('priority');
        if (!$field) {
            return 'no priority field';
        }
        $current = $field->getAnswer() ? (int) $field->getAnswer()->getIdValue() : 0;
        if ($current === $priorityId) {
            return null;
        }
        $source = array();
        foreach ($field->getFormNames() as $name) {
            $source[$name] = (string) $priorityId;
        }
        $form = $field->getEditForm($source);
        $errors = array();
        if (!$form->isValid() || !$ticket->updateField($form, $errors)) {
            return self::first($errors ?: $form->errors()) ?: 'refused by osTicket';
        }
        return true;
    }

    /**
     * @return string|null  change description, 'error:...' or null if nothing changed
     */
    private static function changeDue(Ticket $ticket, string $action, int $days)
    {
        if ($action === 'clear') {
            if (!$ticket->getDueDate()) {
                return null;
            }
            $ticket->duedate = null;
            // The overdue flag goes only if the SLA doesn't keep it overdue.
            $sla = $ticket->getSLADueDate();
            if (!$sla || Misc::db2gmtime($sla) > Misc::gmtime()) {
                $ticket->isoverdue = 0;
            }
            return $ticket->save() ? 'Due date cleared' : 'error:could not save';
        }

        // NOW() of the database, so the stored value is in the database's
        // timezone like every other due date.
        $res = db_query(sprintf('SELECT NOW() + INTERVAL %d DAY', max(1, min(TicketAutomationRule::DUE_DAYS_MAX, $days))));
        $row = $res ? db_fetch_row($res) : null;
        if (!$row) {
            return 'error:no database time';
        }
        $ticket->duedate = (string) $row[0];
        $ticket->isoverdue = 0;
        return $ticket->save() ? 'Due date set to ' . $row[0] : 'error:could not save';
    }

    /**
     * The message that opens a ticket is its creation, not a reply. osTicket
     * sets FLAG_ORIGINAL_MESSAGE only after Ticket::create() has posted it,
     * so at signal time the flag is still missing: the first message of a
     * thread is recognised by having no earlier message.
     */
    private static function isOriginalMessage(ThreadEntry $entry, $thread): bool
    {
        if ($entry->hasFlag(ThreadEntry::FLAG_ORIGINAL_MESSAGE)) {
            return true;
        }
        if ($entry->getType() !== 'M') {
            return false;
        }
        $res = db_query(sprintf("SELECT COUNT(*) FROM %s WHERE thread_id=%d AND type='M' AND id<%d",
            THREAD_ENTRY_TABLE, (int) $thread->getId(), (int) $entry->getId()));
        $row = $res ? db_fetch_row($res) : null;
        return !$row || (int) $row[0] === 0;
    }

    /** Plain facts the matcher works on. */
    private static function facts(Ticket $ticket): array
    {
        $statuses = TicketAutomationCatalog::statuses();
        $sid = (int) $ticket->getStatusId();
        return array(
            'status_id'    => $sid,
            'status_state' => $statuses[$sid]['state'] ?? '',
            'dept_id'      => (int) $ticket->getDeptId(),
            'topic_id'     => (int) $ticket->getTopicId(),
            'assigned'     => (bool) $ticket->isAssigned(),
        );
    }

    private static function first($errors): string
    {
        if (!is_array($errors)) {
            return (string) $errors;
        }
        foreach ($errors as $e) {
            if (is_array($e)) {
                $e = self::first($e);
            }
            if ((string) $e !== '') {
                return (string) $e;
            }
        }
        return '';
    }

    /** System log (Admin Panel, Dashboard, System Logs), never a mail. */
    private static function log(string $message, bool $error = false): void
    {
        global $ost;
        if (!$ost) {
            return;
        }
        if ($error) {
            $ost->logError(self::NOTE_TITLE, $message, false);
        } else {
            $ost->logWarning(self::NOTE_TITLE, $message, false);
        }
    }
}
