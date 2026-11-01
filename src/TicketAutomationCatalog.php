<?php

declare(strict_types=1);

/**
 * Lists for the config form and the runtime checks: statuses, departments,
 * help topics, priorities.
 *
 * Plain SQL on purpose. The config is built during plugin bootstrap, before
 * osTicket has loaded what TicketStatus::getLocalName() and the other model
 * helpers need. "Enabled" is bit 1 of the status `mode` column
 * (TicketStatus::ENABLED), the bit the core status menus check.
 */
final class TicketAutomationCatalog
{
    /** @var array<int, array{name:string, state:string, enabled:bool}>|null */
    private static $statuses;

    /**
     * Every status, id => name, state, enabled. Cached per request.
     *
     * @return array<int, array{name:string, state:string, enabled:bool}>
     */
    public static function statuses(bool $fresh = false): array
    {
        if (self::$statuses !== null && !$fresh) {
            return self::$statuses;
        }
        $out = array();
        if (self::ready() && defined('TICKET_STATUS_TABLE')) {
            $res = db_query('SELECT id, name, state, mode FROM ' . TICKET_STATUS_TABLE . ' ORDER BY sort, name');
            while ($res && ($row = db_fetch_array($res))) {
                $out[(int) $row['id']] = array(
                    'name'    => (string) $row['name'],
                    'state'   => (string) $row['state'],
                    'enabled' => ((int) $row['mode'] & 1) === 1,
                );
            }
        }
        return self::$statuses = $out;
    }

    /** Open statuses for the condition picker, disabled ones marked. */
    public static function conditionChoices(): array
    {
        $out = array();
        foreach (self::statuses() as $id => $s) {
            if ($s['state'] === 'open') {
                $out[(string) $id] = $s['name'] . ($s['enabled'] ? '' : ' (disabled)');
            }
        }
        return $out;
    }

    /** Enabled open and closed statuses for the target picker. */
    public static function targetChoices(): array
    {
        $out = array('0' => '— keep status —');
        foreach (self::statuses() as $id => $s) {
            if ($s['enabled'] && in_array($s['state'], array('open', 'closed'), true)) {
                $out[(string) $id] = $s['name'] . ($s['state'] === 'closed' ? ' (closed)' : '');
            }
        }
        return $out;
    }

    public static function departments(): array
    {
        return self::pairs(defined('DEPT_TABLE') ? 'SELECT id, name FROM ' . DEPT_TABLE . ' ORDER BY name' : '');
    }

    public static function topics(): array
    {
        return self::pairs(defined('TOPIC_TABLE') ? 'SELECT topic_id, topic FROM ' . TOPIC_TABLE . ' ORDER BY topic' : '');
    }

    public static function priorities(): array
    {
        return array('0' => '— keep priority —') + self::pairs(defined('PRIORITY_TABLE')
            ? 'SELECT priority_id, priority_desc FROM ' . PRIORITY_TABLE . ' ORDER BY priority_urgency' : '');
    }

    /** @var array<string, array<string, string>> query => pairs, per request */
    private static $pairs = array();

    private static function pairs(string $sql): array
    {
        if ($sql === '' || !self::ready()) {
            return array();
        }
        if (isset(self::$pairs[$sql])) {
            return self::$pairs[$sql];
        }
        $out = array();
        $res = db_query($sql);
        while ($res && ($row = db_fetch_row($res))) {
            $out[(string) (int) $row[0]] = (string) $row[1];
        }
        return self::$pairs[$sql] = $out;
    }

    private static function ready(): bool
    {
        return function_exists('db_query') && function_exists('db_fetch_array');
    }
}
