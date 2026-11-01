<?php

declare(strict_types=1);

/**
 * One automation rule: the settings of one plugin instance, normalised into
 * a plain array, and the checks that decide whether such a rule may be saved.
 *
 * Pure PHP: no osTicket class, no database. The status catalogue is passed in,
 * so the unit tests can run the very same validation the config form runs.
 *
 * Normalised shape:
 *
 *   id, name          instance ID and name
 *   dry_run           true: log what would happen, change nothing
 *   triggers          user_message, collab_message, agent_reply, internal_note, due  => bool
 *   statuses          int[] current status, at least one (open statuses only)
 *   depts, topics     int[] empty = any
 *   only_assigned     bool
 *   skip_auto         bool  ignore bounces and auto-replies (message triggers)
 *   due_source        'manual' | 'manual_or_sla'
 *   set_status        int   0 = keep
 *   set_priority      int   0 = keep
 *   due_action        'keep' | 'clear' | 'set'
 *   due_days          int   1..365, used with 'set'
 *   mark_unanswered   bool
 *   note              string template for the internal note, always written
 */
final class TicketAutomationRule
{
    public const TRIGGERS = array('user_message', 'collab_message', 'agent_reply', 'internal_note', 'due');
    public const MESSAGE_TRIGGERS = array('user_message', 'collab_message', 'agent_reply', 'internal_note');

    /** Config field name => trigger. */
    public const TRIGGER_FIELDS = array(
        'trig_user'   => 'user_message',
        'trig_collab' => 'collab_message',
        'trig_reply'  => 'agent_reply',
        'trig_note'   => 'internal_note',
        'trig_due'    => 'due',
    );

    /** Every stored setting of a rule, as named in TicketAutomationConfig. */
    public const CONFIG_KEYS = array(
        'dry_run', 'trig_user', 'trig_collab', 'trig_reply', 'trig_note', 'trig_due', 'due_source',
        'cond_status', 'cond_dept', 'cond_topic', 'cond_assigned', 'skip_auto',
        'act_status', 'act_priority', 'act_due', 'act_due_days', 'act_unanswered', 'act_note',
    );

    public const DEFAULT_NOTE = 'Rule "{rule}" ran on {trigger} from {sender}. {changes}';
    public const NOTE_MAX = 2000;
    public const DUE_DAYS_MAX = 365;

    /**
     * @param array<string, mixed> $raw  config values as PluginConfig::get() returns them
     */
    public static function normalise(array $raw, int $id = 0, string $name = ''): array
    {
        $triggers = array();
        foreach (self::TRIGGER_FIELDS as $field => $trigger) {
            // Customer messages are the reason the plugin exists: on unless
            // switched off. Everything else is off unless switched on.
            $default = $trigger === 'user_message';
            $triggers[$trigger] = array_key_exists($field, $raw) && $raw[$field] !== null
                ? self::bool($raw[$field]) : $default;
        }

        $dueAction = (string) self::scalar($raw['act_due'] ?? 'keep');
        if (!in_array($dueAction, array('keep', 'clear', 'set'), true)) {
            $dueAction = 'keep';
        }
        $dueSource = (string) self::scalar($raw['due_source'] ?? 'manual');
        if (!in_array($dueSource, array('manual', 'manual_or_sla'), true)) {
            $dueSource = 'manual';
        }

        $note = trim(str_replace("\r\n", "\n", (string) self::scalar($raw['act_note'] ?? '')));
        if ($note === '') {
            $note = self::DEFAULT_NOTE;
        }

        return array(
            'id'              => $id,
            'name'            => $name !== '' ? $name : ('#' . $id),
            'dry_run'         => self::bool($raw['dry_run'] ?? false),
            'triggers'        => $triggers,
            'statuses'        => self::ids($raw['cond_status'] ?? null),
            'depts'           => self::ids($raw['cond_dept'] ?? null),
            'topics'          => self::ids($raw['cond_topic'] ?? null),
            'only_assigned'   => self::bool($raw['cond_assigned'] ?? false),
            'skip_auto'       => array_key_exists('skip_auto', $raw) && $raw['skip_auto'] !== null
                                    ? self::bool($raw['skip_auto']) : true,
            'due_source'      => $dueSource,
            'set_status'      => self::id($raw['act_status'] ?? null),
            'set_priority'    => self::id($raw['act_priority'] ?? null),
            'due_action'      => $dueAction,
            'due_days'        => self::days($raw['act_due_days'] ?? null),
            'mark_unanswered' => self::bool($raw['act_unanswered'] ?? false),
            'note'            => self::cut($note, self::NOTE_MAX),
        );
    }

    /**
     * Reasons a rule may not be saved, field name => message. Also run before
     * every execution, so a status disabled after the rule was saved stops
     * the rule instead of being set anyway.
     *
     * @param array<int, array{name:string, state:string, enabled:bool}> $statuses  id => info, every status
     * @param array<string, mixed> $raw  unnormalised values, for the range checks
     * @return array<string, string>
     */
    public static function validate(array $rule, array $statuses, array $raw = array()): array
    {
        $errors = array();

        if (!in_array(true, $rule['triggers'], true)) {
            $errors['trig_user'] = 'Select at least one trigger.';
        }

        if (!$rule['statuses']) {
            $errors['cond_status'] = 'Select at least one current status. A rule for every status is not supported on purpose.';
        }
        foreach ($rule['statuses'] as $sid) {
            if (!isset($statuses[$sid]) || $statuses[$sid]['state'] !== 'open') {
                $errors['cond_status'] = 'Only open statuses can be a condition. osTicket reopens closed tickets itself when a message arrives.';
                break;
            }
        }

        $target = (int) $rule['set_status'];
        if ($target > 0) {
            $info = $statuses[$target] ?? null;
            if (!$info) {
                $errors['act_status'] = 'The target status does not exist.';
            } elseif (!$info['enabled']) {
                $errors['act_status'] = 'The target status is disabled in the status list.';
            } elseif (!in_array($info['state'], array('open', 'closed'), true)) {
                $errors['act_status'] = 'The target status must be an open or closed status.';
            } elseif ($info['state'] === 'closed' && self::hasMessageTrigger($rule)) {
                $errors['act_status'] = 'A closed status can only be the target of the due-date trigger. '
                    . 'On a message osTicket reopens a closed ticket right after the rule ran.';
            }
        }

        if ($rule['due_action'] === 'set' && array_key_exists('act_due_days', $raw)) {
            $days = $raw['act_due_days'];
            if (!is_numeric($days) || (int) $days < 1 || (int) $days > self::DUE_DAYS_MAX || (string) (int) $days !== trim((string) $days)) {
                $errors['act_due_days'] = 'Days must be a whole number between 1 and ' . self::DUE_DAYS_MAX . '.';
            }
        }

        if (!empty($rule['triggers']['due']) && !self::dueMakesProgress($rule)) {
            $errors['trig_due'] = 'With the due-date trigger the rule must move the ticket out of the condition: '
                . 'set a status that is not among the current statuses, or clear or postpone the due date. '
                . 'Otherwise it would run again on every cron call.';
        }

        if (array_key_exists('act_note', $raw) && self::length(trim((string) self::scalar($raw['act_note']))) > self::NOTE_MAX) {
            $errors['act_note'] = 'The note template is longer than ' . self::NOTE_MAX . ' characters.';
        }

        return $errors;
    }

    /**
     * Whether one run on a due ticket ends the condition that selected it,
     * so the next cron call does not pick the same ticket again.
     */
    public static function dueMakesProgress(array $rule): bool
    {
        $target = (int) $rule['set_status'];
        if ($target > 0 && !in_array($target, $rule['statuses'], true)) {
            return true;
        }
        return in_array($rule['due_action'], array('clear', 'set'), true);
    }

    public static function hasMessageTrigger(array $rule): bool
    {
        foreach (self::MESSAGE_TRIGGERS as $t) {
            if (!empty($rule['triggers'][$t])) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------ parsing

    public static function bool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), array('1', 'true', 'on', 'yes'), true);
        }
        return false;
    }

    /**
     * IDs from a ChoiceField value: multiselect gives id => label, single
     * select gives the id, the database a JSON string. Only positive integers
     * survive, sorted, without duplicates.
     *
     * @return int[]
     */
    public static function ids($v): array
    {
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } elseif (trim($v) === '') {
                return array();
            } else {
                $v = array_map('trim', explode(',', $v));
                $v = array_combine($v, $v);
            }
        }
        if (is_int($v)) {
            $v = array($v => $v);
        }
        if (!is_array($v)) {
            return array();
        }
        $out = array();
        foreach ($v as $key => $label) {
            // Keys carry the IDs in the multiselect shape; plain lists
            // carry them as values.
            $candidate = is_int($key) && self::isList($v) ? $label : $key;
            if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                $n = (int) $candidate;
                if ($n > 0) {
                    $out[$n] = $n;
                }
            }
        }
        sort($out);
        return array_values($out);
    }

    public static function id($v): int
    {
        $ids = self::ids($v);
        return $ids ? $ids[0] : 0;
    }

    private static function days($v): int
    {
        $v = self::scalar($v);
        if (!is_numeric($v)) {
            return 1;
        }
        return max(1, min(self::DUE_DAYS_MAX, (int) $v));
    }

    /** First key of a one-element choice array, else the value itself. */
    private static function scalar($v)
    {
        if (is_array($v)) {
            if (!$v) {
                return '';
            }
            reset($v);
            return (string) key($v);
        }
        return $v;
    }

    // osTicket requires mbstring; the fallbacks only keep the unit tests
    // runnable on a bare PHP.

    private static function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function cut(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
    }

    /** array_is_list() is PHP 8.1; the plugin supports 8.0. */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}
