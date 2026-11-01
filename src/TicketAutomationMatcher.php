<?php

declare(strict_types=1);

/**
 * Decides which rule, if any, runs for an event. Pure PHP, no osTicket:
 * the runner turns thread entries and tickets into plain fact arrays first.
 *
 * Entry facts:   type ('M', 'R', 'N', ...), user_id, staff_id, owner_id,
 *                system (bool), original (bool), auto (bool, bounce or auto-reply)
 * Ticket facts:  status_id, status_state, dept_id, topic_id, assigned (bool)
 */
final class TicketAutomationMatcher
{
    /**
     * Which trigger a new thread entry is, or null if it is none.
     *
     * The first message of a ticket is its creation, not a reply. System
     * entries are what osTicket and plugins write themselves, this plugin's
     * own notes among them; they never trigger a rule.
     */
    public static function classify(array $e): ?string
    {
        if (!empty($e['original']) || !empty($e['system'])) {
            return null;
        }
        $type = (string) ($e['type'] ?? '');
        $user = (int) ($e['user_id'] ?? 0);
        $staff = (int) ($e['staff_id'] ?? 0);

        if ($type === 'M') {
            if ($user <= 0) {
                return null;
            }
            return $user === (int) ($e['owner_id'] ?? 0) ? 'user_message' : 'collab_message';
        }
        if ($type === 'R') {
            return $staff > 0 ? 'agent_reply' : null;
        }
        if ($type === 'N') {
            return $staff > 0 ? 'internal_note' : null;
        }
        return null;
    }

    /**
     * Whether a rule applies. $auto only matters for message triggers.
     */
    public static function matches(array $rule, string $trigger, array $t, bool $auto = false): bool
    {
        if (empty($rule['triggers'][$trigger])) {
            return false;
        }
        if ($auto && $rule['skip_auto'] && $trigger !== 'due') {
            return false;
        }
        if (($t['status_state'] ?? '') !== 'open') {
            return false;
        }
        if (!$rule['statuses'] || !in_array((int) ($t['status_id'] ?? 0), $rule['statuses'], true)) {
            return false;
        }
        if ($rule['depts'] && !in_array((int) ($t['dept_id'] ?? 0), $rule['depts'], true)) {
            return false;
        }
        if ($rule['topics'] && !in_array((int) ($t['topic_id'] ?? 0), $rule['topics'], true)) {
            return false;
        }
        if ($rule['only_assigned'] && empty($t['assigned'])) {
            return false;
        }
        return true;
    }

    /**
     * First matching rule in the given order, or null. One event runs at
     * most one rule: a rule that moves a ticket into the status another rule
     * watches must not set off a chain within the same event.
     *
     * @param array<int, array> $rules  in instance order
     */
    public static function pick(array $rules, string $trigger, array $t, bool $auto = false): ?array
    {
        foreach ($rules as $rule) {
            if (self::matches($rule, $trigger, $t, $auto)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * SQL selecting the IDs of tickets a due-date rule considers, oldest due
     * date first. Only integers reach the statement. Status, department,
     * topic and assignment are checked again per ticket by matches().
     */
    public static function dueSql(array $rule, string $ticketTable, string $statusTable, int $limit = 100): string
    {
        $ids = static function (array $list): string {
            return implode(',', array_map('intval', $list ?: array(0)));
        };
        $due = "(t.duedate IS NOT NULL AND t.duedate <= NOW())";
        if ($rule['due_source'] === 'manual_or_sla') {
            $due = "($due OR (t.duedate IS NULL AND t.est_duedate IS NOT NULL AND t.est_duedate <= NOW()))";
        }
        $sql = "SELECT t.ticket_id FROM $ticketTable t"
            . " JOIN $statusTable s ON s.id = t.status_id"
            . " WHERE s.state = 'open'"
            . " AND t.status_id IN (" . $ids($rule['statuses']) . ")"
            . " AND $due";
        if ($rule['depts']) {
            $sql .= " AND t.dept_id IN (" . $ids($rule['depts']) . ")";
        }
        if ($rule['topics']) {
            $sql .= " AND t.topic_id IN (" . $ids($rule['topics']) . ")";
        }
        if ($rule['only_assigned']) {
            $sql .= " AND (t.staff_id > 0 OR t.team_id > 0)";
        }
        return $sql . " ORDER BY COALESCE(t.duedate, t.est_duedate), t.ticket_id LIMIT " . max(1, min(500, $limit));
    }

    /**
     * Fills the note template. Values are plain text; the note is stored as
     * a text body, which osTicket escapes on display. Unknown placeholders
     * stay as they are, so a typo shows up in the note instead of vanishing.
     *
     * @param array<string, string> $vars
     */
    public static function render(string $template, array $vars): string
    {
        $map = array();
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = str_replace(array("\r", "\n"), ' ', (string) $v);
        }
        return trim(strtr($template, $map));
    }

    /** Human-readable trigger names for the note and the log. */
    public static function triggerLabel(string $trigger): string
    {
        $labels = array(
            'user_message'   => 'a message from the customer',
            'collab_message' => 'a message from a collaborator',
            'agent_reply'    => 'an agent reply',
            'internal_note'  => 'an internal note',
            'due'            => 'the due date',
        );
        return $labels[$trigger] ?? $trigger;
    }
}
