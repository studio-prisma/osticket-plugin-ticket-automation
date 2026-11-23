<?php

declare(strict_types=1);

/**
 * Unit tests: no osTicket, no database.
 *
 *   php tests/unit.php
 *
 * Rule parsing and validation, entry classification, matching, the
 * one-rule-per-event order, the due-date query, the note template, and the
 * runner against stand-in tickets: loop protection, bounces, refused target
 * statuses, dry run, cron selection, agent context restored.
 */

define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('TICKET_TABLE', 'ost_ticket');
define('TICKET_STATUS_TABLE', 'ost_ticket_status');
define('DEPT_TABLE', 'ost_department');
define('TOPIC_TABLE', 'ost_help_topic');
define('PRIORITY_TABLE', 'ost_ticket_priority');
define('THREAD_ENTRY_TABLE', 'ost_thread_entry');
define('THREAD_ENTRY_EMAIL_TABLE', 'ost_thread_entry_email');

require __DIR__ . '/stubs/class.mailer.php';

// ------------------------------------------------------------ fake database

final class FakeResult
{
    public $rows;
    public $i = 0;
    function __construct(array $rows) { $this->rows = $rows; }
}

$GLOBALS['fake_statuses'] = array();
$GLOBALS['fake_due'] = null;   // callable(string $sql): int[] ticket IDs
$GLOBALS['fake_sql'] = array();
$GLOBALS['fake_prior'] = 1;   // earlier messages in the thread
$GLOBALS['fake_mids'] = array();     // stored Message-ID (with brackets) => thread entry ID
$GLOBALS['fake_threads'] = array();  // thread entry ID => thread ID

function db_input($v) { return "'" . addslashes((string) $v) . "'"; }

function db_query($sql)
{
    $GLOBALS['fake_sql'][] = $sql;
    if (preg_match('/^SELECT thread_entry_id FROM ' . THREAD_ENTRY_EMAIL_TABLE . ' WHERE mid IN \((.*)\) AND thread_entry_id<>(\d+) LIMIT 1$/', $sql, $m)) {
        foreach ($GLOBALS['fake_mids'] as $mid => $eid) {
            if ($eid !== (int) $m[2] && strpos($m[1], db_input($mid)) !== false) {
                return new FakeResult(array(array('thread_entry_id' => $eid)));
            }
        }
        return new FakeResult(array());
    }
    if (preg_match('/^SELECT thread_id FROM ' . THREAD_ENTRY_TABLE . ' WHERE id=(\d+)$/', $sql, $m)) {
        $tid = $GLOBALS['fake_threads'][(int) $m[1]] ?? null;
        return new FakeResult($tid === null ? array() : array(array('thread_id' => $tid)));
    }
    if (strpos($sql, 'FROM ' . TICKET_STATUS_TABLE) !== false && strpos($sql, 'SELECT id, name, state, mode') === 0) {
        return new FakeResult($GLOBALS['fake_statuses']);
    }
    if (strpos($sql, 'SELECT t.ticket_id') === 0) {
        $ids = $GLOBALS['fake_due'] ? ($GLOBALS['fake_due'])($sql) : array();
        return new FakeResult(array_map(fn($id) => array('ticket_id' => $id), $ids));
    }
    if (preg_match('/^SELECT ticket_id, status_id, duedate, est_duedate, updated, lastupdate FROM ' . TICKET_TABLE
        . ' WHERE ticket_id IN \(([\d,]+)\)$/', $sql, $m)) {
        $rows = array();
        foreach (explode(',', $m[1]) as $id) {
            $t = Ticket::$all[(int) $id] ?? null;
            if ($t) {
                $rows[] = array('ticket_id' => $t->id, 'status_id' => $t->status, 'duedate' => $t->duedate,
                    'est_duedate' => $t->est, 'updated' => $t->updated, 'lastupdate' => null);
            }
        }
        return new FakeResult($rows);
    }
    if (strpos($sql, 'SELECT COUNT(*) FROM ' . THREAD_ENTRY_TABLE) === 0) {
        return new FakeResult(array(array('n' => $GLOBALS['fake_prior'])));
    }
    if (strpos($sql, 'SELECT NOW() + INTERVAL') === 0) {
        return new FakeResult(array(array('d' => '2026-11-04 10:00:00')));
    }
    return new FakeResult(array());
}
function db_fetch_array($res) { return $res->rows[$res->i++] ?? null; }
function db_fetch_row($res) { $r = db_fetch_array($res); return $r === null ? null : array_values($r); }

// ------------------------------------------------------ fake osTicket model

class Misc
{
    static function db2gmtime($d) { return strtotime((string) $d); }
    static function gmtime() { return strtotime('2026-11-01 12:00:00'); }
}

class TextThreadEntryBody
{
    public $text;
    function __construct($t) { $this->text = $t; }
}

class ThreadEntry
{
    const FLAG_ORIGINAL_MESSAGE = 0x0001;
    const FLAG_SYSTEM = 0x0080;
    public $ht;
    function __construct(array $ht) { $this->ht = $ht + array('flags' => 0, 'auto' => false, 'name' => 'Someone'); }
    function getThread() { return new FakeThread((int) $this->ht['ticket']); }
    function getId() { return $this->ht['id'] ?? 500; }
    function getType() { return $this->ht['type']; }
    function get($k) { return $this->ht[$k] ?? null; }
    function isSystem() { return ($this->ht['flags'] & self::FLAG_SYSTEM) !== 0; }
    function hasFlag($f) { return ($this->ht['flags'] & $f) !== 0; }
    function isBounceOrAutoReply() { return (bool) $this->ht['auto']; }
    function getName() { return $this->ht['name']; }
    function getEmailHeaderArray() { return $this->ht['headers'] ?? null; }
    function getEmailMessageId() { return $this->ht['mid'] ?? ''; }
    function getBody() { return $this->ht['body'] ?? ''; }
}

class FakeThread
{
    private $id;
    function __construct(int $id) { $this->id = $id; }
    function getId() { return $this->id + 1000; }
    function getObjectType() { return 'T'; }
    function getObjectId() { return $this->id; }
}

class Ticket
{
    public static $all = array();
    public $id, $status, $user = 10, $dept = 1, $topic = 2, $assigned = true, $answered = true;
    public $duedate = null, $isoverdue = 0, $est = null, $updated = '2026-11-01 08:00:00';
    public $notes = array(), $statusCalls = 0, $failStatus = false, $throwOnStatus = false;
    /** Simulates osTicket posting the plugin's note: a nested signal. */
    public $nestedEntry = null;
    public $staffSeen = 'unset';

    static function lookup($id) { return self::$all[(int) $id] ?? null; }
    function __construct(int $id, int $status) { $this->id = $id; $this->status = $status; self::$all[$id] = $this; }
    function getId() { return $this->id; }
    function getNumber() { return (string) (100000 + $this->id); }
    function getUserId() { return $this->user; }
    function getStatusId() { return $this->status; }
    function getDeptId() { return $this->dept; }
    function getTopicId() { return $this->topic; }
    function isAssigned() { return $this->assigned; }
    function isAnswered() { return $this->answered; }
    function markUnAnswered() { $this->answered = false; return true; }
    function getDueDate() { return $this->duedate; }
    function getSLADueDate() { return null; }
    function getField($n) { return null; }
    function save() { return true; }
    function setStatus($id, $comments, &$errors, $closingAgent)
    {
        $this->staffSeen = $GLOBALS['thisstaff'] ?? null;
        if ($this->throwOnStatus) {
            throw new RuntimeException('boom');
        }
        $this->statusCalls++;
        if ($this->failStatus) {
            $errors['err'] = 'cannot';
            return false;
        }
        $this->status = (int) $id;
        return true;
    }
    function logNote($title, $body, $poster, $alert)
    {
        $this->notes[] = array('title' => $title, 'text' => $body->text, 'poster' => $poster, 'alert' => $alert);
        if ($this->nestedEntry) {
            TicketAutomationRunner::onThreadEntry($this->nestedEntry);
        }
    }
}

final class FakeOst
{
    public $logs = array();
    function logWarning($t, $m, $a) { $this->logs[] = array('warn', $m, $a); }
    function logError($t, $m, $a) { $this->logs[] = array('error', $m, $a); }
}
$GLOBALS['ost'] = new FakeOst();

/** Stand-in for the plugin's rows in osTicket's config table. */
final class FakeStore
{
    public $rows = array();
    public $writes = 0;
    function all(): array { return $this->rows; }
    function set(string $k, string $v): void
    {
        $this->writes++;
        if ($v === '') {
            unset($this->rows[$k]);
        } else {
            $this->rows[$k] = $v;
        }
    }
}
$GLOBALS['fake_store'] = new FakeStore();
$GLOBALS['fake_now'] = strtotime('2026-11-01 12:00:00');

$dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
require $dir . '/class.TicketAutomationPlugin.php';
TicketAutomationRunner::$store = $GLOBALS['fake_store'];
TicketAutomationRunner::$clock = fn() => $GLOBALS['fake_now'];

// ------------------------------------------------------------------ harness

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

/** Status catalogue: 1 Open, 6 waiting customer, 9 waiting vendor, 12 resubmission, 3 Closed, 20 disabled. */
function statuses(array $override = array()): array
{
    $rows = array(
        1  => array('Open', 'open', 3),
        6  => array('Waiting for customer', 'open', 3),
        9  => array('Waiting for vendor', 'open', 3),
        12 => array('Resubmission', 'open', 3),
        3  => array('Closed', 'closed', 3),
        20 => array('Parked', 'open', 0),
        5  => array('Archived', 'archived', 3),
    );
    foreach ($override as $id => $row) {
        $rows[$id] = $row;
    }
    $GLOBALS['fake_statuses'] = array();
    foreach ($rows as $id => $r) {
        $GLOBALS['fake_statuses'][] = array('id' => $id, 'name' => $r[0], 'state' => $r[1], 'mode' => $r[2]);
    }
    return TicketAutomationCatalog::statuses(true);
}

function rule(array $raw, int $id = 1, string $name = 'Rule'): array
{
    return TicketAutomationRule::normalise($raw, $id, $name);
}

/** A new request. $keep: tickets and stored markers survive, as between two cron calls. */
function resetRunner(array $rules, bool $keep = false): void
{
    $tickets = Ticket::$all;
    if (!$keep) {
        $GLOBALS['fake_store']->rows = array();
        $GLOBALS['fake_store']->writes = 0;
        $GLOBALS['fake_now'] = strtotime('2026-11-01 12:00:00');
    }
    $r = new ReflectionClass('TicketAutomationRunner');
    foreach (array('rules' => array(), 'done' => array(), 'running' => false) as $prop => $val) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue(null, $val);
    }
    foreach ($rules as $rule) {
        TicketAutomationRunner::register($rule);
    }
    Ticket::$all = $keep ? $tickets : array();
    if (!$keep) {
        $GLOBALS['ost']->logs = array();
        $GLOBALS['fake_due'] = null;
    }
}

/** Due-date query stand-in: these tickets, minus the ones the SQL excludes. */
function dueIds(array $ids): callable
{
    return function (string $sql) use ($ids) {
        $m = array();
        $out = preg_match('/NOT IN \(([\d,]+)\)/', $sql, $m) ? array_map('intval', explode(',', $m[1])) : array();
        return array_values(array_filter($ids, fn($id) => !in_array($id, $out, true)));
    };
}

function msg(int $ticket, array $extra = array()): ThreadEntry
{
    return new ThreadEntry($extra + array('type' => 'M', 'user_id' => 10, 'staff_id' => 0, 'ticket' => $ticket, 'name' => 'Customer'));
}

$cat = statuses();

// ------------------------------------------------------------- normalise

$d = rule(array());
check('defaults: only the customer trigger is on', $d['triggers'] === array(
    'user_message' => true, 'collab_message' => false, 'agent_reply' => false, 'internal_note' => false, 'due' => false));
check('defaults: bounces ignored, nothing else changes', $d['skip_auto'] === true && $d['set_status'] === 0
    && $d['due_action'] === 'keep' && !$d['mark_unanswered'] && !$d['dry_run'] && $d['set_priority'] === 0);
check('defaults: note template and manual due date', $d['note'] === TicketAutomationRule::DEFAULT_NOTE && $d['due_source'] === 'manual');
check('defaults: explicit false switches the customer trigger off', rule(array('trig_user' => false))['triggers']['user_message'] === false);
check('defaults: skip_auto can be switched off', rule(array('skip_auto' => false))['skip_auto'] === false);

check('ids: multiselect id => label', TicketAutomationRule::ids(array(9 => 'B', 6 => 'A')) === array(6, 9));
check('ids: JSON from the database', TicketAutomationRule::ids('{"12":"Resubmission","6":"x"}') === array(6, 12));
check('ids: single select key', TicketAutomationRule::ids('6') === array(6) && TicketAutomationRule::id('6') === 6);
check('ids: zero, negatives, junk dropped', TicketAutomationRule::ids('0') === array()
    && TicketAutomationRule::ids(array('-3' => 'x', 'a' => 'y', '4' => 'z')) === array(4)
    && TicketAutomationRule::ids(null) === array() && TicketAutomationRule::ids('') === array()
    && TicketAutomationRule::ids("1 OR 1=1") === array());
check('ids: plain list of values', TicketAutomationRule::ids(array(6, 9, 6)) === array(6, 9));
check('ids: comma list', TicketAutomationRule::ids('6, 9') === array(6, 9));
check('choice value from a one-element array', rule(array('act_due' => array('clear' => 'Clear')))['due_action'] === 'clear');
check('unknown due action falls back to keep', rule(array('act_due' => 'explode'))['due_action'] === 'keep');
check('days clamped', rule(array('act_due_days' => '9999'))['due_days'] === 365 && rule(array('act_due_days' => 'x'))['due_days'] === 1);
check('note: trimmed, CRLF normalised, capped', rule(array('act_note' => "  a\r\nb  "))['note'] === "a\nb"
    && strlen(rule(array('act_note' => str_repeat('x', 5000)))['note']) === TicketAutomationRule::NOTE_MAX);
check('config keys: every stored setting is read at bootstrap', (function () {
    $c = new TicketAutomationConfig();
    $fields = array_filter(array_keys($c->getOptions()), fn($k) => strpos($k, 'sec_') !== 0);
    return array_values($fields) === TicketAutomationRule::CONFIG_KEYS;
})());

// --------------------------------------------------------------- validate

$v = fn(array $raw) => TicketAutomationRule::validate(rule($raw), $cat, $raw);
$base = array('cond_status' => array(6 => 'x'), 'act_status' => '1');

check('valid: customer message, waiting -> open', $v($base) === array(), json_encode($v($base)));
check('invalid: no status condition', isset($v(array('act_status' => '1'))['cond_status']));
check('invalid: closed status as condition', isset($v(array('cond_status' => array(3 => 'x')))['cond_status']));
check('invalid: unknown status as condition', isset($v(array('cond_status' => array(77 => 'x')))['cond_status']));
check('invalid: no trigger at all', isset($v($base + array('trig_user' => false))['trig_user']));
check('invalid: disabled target status', isset($v(array('cond_status' => array(6 => 'x'), 'act_status' => '20'))['act_status']));
check('invalid: unknown target status', isset($v(array('cond_status' => array(6 => 'x'), 'act_status' => '77'))['act_status']));
check('invalid: archived target status', isset($v(array('cond_status' => array(6 => 'x'), 'act_status' => '5'))['act_status']));
check('invalid: closed target with a message trigger', isset($v(array('cond_status' => array(6 => 'x'), 'act_status' => '3'))['act_status']));
check('valid: closed target with the due trigger only', $v(array('cond_status' => array(12 => 'x'), 'act_status' => '3',
    'trig_user' => false, 'trig_due' => true)) === array());
check('invalid: due trigger without progress', isset($v(array('cond_status' => array(12 => 'x'), 'trig_due' => true))['trig_due']));
check('invalid: due trigger, target among the conditions', isset($v(array('cond_status' => array(12 => 'x', 6 => 'y'),
    'act_status' => '6', 'trig_due' => true))['trig_due']));
check('valid: due trigger with a status leaving the condition', $v(array('cond_status' => array(12 => 'x'),
    'act_status' => '1', 'trig_due' => true)) === array());
check('valid: due trigger that clears the due date', $v(array('cond_status' => array(12 => 'x'),
    'act_due' => 'clear', 'trig_due' => true)) === array());
check('valid: due trigger that postpones the due date', $v(array('cond_status' => array(12 => 'x'),
    'act_due' => 'set', 'act_due_days' => '3', 'trig_due' => true)) === array());
check('invalid: days out of range or not a whole number', isset($v($base + array('act_due' => 'set', 'act_due_days' => '0'))['act_due_days'])
    && isset($v($base + array('act_due' => 'set', 'act_due_days' => '400'))['act_due_days'])
    && isset($v($base + array('act_due' => 'set', 'act_due_days' => '2.5'))['act_due_days'])
    && !isset($v($base + array('act_due' => 'set', 'act_due_days' => '7'))['act_due_days']));
check('invalid: note template too long', isset($v($base + array('act_note' => str_repeat('x', 2001)))['act_note']));
check('valid: note-only rule (no status change)', $v(array('cond_status' => array(6 => 'x'))) === array());

// --------------------------------------------------------------- classify

$c = fn(array $e) => TicketAutomationMatcher::classify($e + array('user_id' => 0, 'staff_id' => 0, 'owner_id' => 10));
check('classify: message from the ticket user', $c(array('type' => 'M', 'user_id' => 10)) === 'user_message');
check('classify: message from someone else is a collaborator', $c(array('type' => 'M', 'user_id' => 11)) === 'collab_message');
check('classify: message without user is nothing', $c(array('type' => 'M')) === null);
check('classify: the ticket\'s first message is its creation', $c(array('type' => 'M', 'user_id' => 10, 'original' => true)) === null);
check('classify: agent reply', $c(array('type' => 'R', 'staff_id' => 3)) === 'agent_reply');
check('classify: agent note', $c(array('type' => 'N', 'staff_id' => 3)) === 'internal_note');
check('classify: system note (our own included) is nothing', $c(array('type' => 'N', 'system' => true)) === null
    && $c(array('type' => 'N')) === null);
check('classify: other entry types are nothing', $c(array('type' => 'A', 'staff_id' => 3)) === null);

// ------------------------------------------------------------ matches/pick

$wk = rule($base, 1, 'WK');
$facts = array('status_id' => 6, 'status_state' => 'open', 'dept_id' => 1, 'topic_id' => 2, 'assigned' => false);
$m = fn(array $rule, string $trig, array $f = array(), bool $auto = false) => TicketAutomationMatcher::matches($rule, $trig, $f + $facts, $auto);

check('match: customer message in the watched status', $m($wk, 'user_message'));
check('match: collaborator message only when switched on', !$m($wk, 'collab_message')
    && $m(rule($base + array('trig_collab' => true)), 'collab_message'));
check('match: agent reply and note off by default', !$m($wk, 'agent_reply') && !$m($wk, 'internal_note'));
check('match: other status', !$m($wk, 'user_message', array('status_id' => 1)));
check('match: closed state never', !$m($wk, 'user_message', array('status_state' => 'closed')));
check('match: bounce or auto-reply skipped', !$m($wk, 'user_message', array(), true));
check('match: auto-reply counts when skipping is off', $m(rule($base + array('skip_auto' => false)), 'user_message', array(), true));
check('match: department and topic lists', $m(rule($base + array('cond_dept' => array(1 => 'x'))), 'user_message')
    && !$m(rule($base + array('cond_dept' => array(4 => 'x'))), 'user_message')
    && !$m(rule($base + array('cond_topic' => array(9 => 'x'))), 'user_message'));
check('match: only assigned', !$m(rule($base + array('cond_assigned' => true)), 'user_message')
    && $m(rule($base + array('cond_assigned' => true)), 'user_message', array('assigned' => true)));

$a = rule($base, 1, 'A');
$b = rule(array('cond_status' => array(1 => 'x', 6 => 'y'), 'act_status' => '9'), 2, 'B');
check('pick: first matching rule wins', TicketAutomationMatcher::pick(array($a, $b), 'user_message', $facts)['name'] === 'A');
check('pick: later rule when the first does not match', TicketAutomationMatcher::pick(array($a, $b), 'user_message',
    array('status_id' => 1) + $facts)['name'] === 'B');
check('pick: none', TicketAutomationMatcher::pick(array($a, $b), 'agent_reply', $facts) === null);

// ----------------------------------------------------------------- due SQL

$sql = TicketAutomationMatcher::dueSql(rule(array('cond_status' => array(12 => 'x'), 'trig_due' => true, 'act_status' => '1')), 'T', 'S');
check('due sql: manual due date only by default', strpos($sql, 't.duedate <= NOW()') !== false && strpos($sql, 'est_duedate <=') === false);
check('due sql: open state and the watched statuses', strpos($sql, "s.state = 'open'") !== false && strpos($sql, 't.status_id IN (12)') !== false);
check('due sql: limited', (bool) preg_match('/LIMIT 100$/', $sql));
$sql = TicketAutomationMatcher::dueSql(rule(array('cond_status' => array(12 => 'x'), 'due_source' => 'manual_or_sla',
    'cond_dept' => array(3 => 'x'), 'cond_assigned' => true)), 'T', 'S', 9999);
check('due sql: SLA due date when chosen, filters, limit capped', strpos($sql, 't.est_duedate <= NOW()') !== false
    && strpos($sql, 't.dept_id IN (3)') !== false && strpos($sql, 't.staff_id > 0') !== false && (bool) preg_match('/LIMIT 500$/', $sql));
$evil = rule(array());
$evil['statuses'] = array('1) OR (1=1');
check('due sql: only integers reach the statement', strpos(TicketAutomationMatcher::dueSql($evil, 'T', 'S'), 'OR (1=1') === false);

// ----------------------------------------------------------------- render

check('render: placeholders filled', TicketAutomationMatcher::render('{rule}: {old_status} -> {new_status}', array(
    'rule' => 'R', 'old_status' => 'A', 'new_status' => 'B')) === 'R: A -> B');
check('render: unknown placeholder kept, newlines in values flattened', TicketAutomationMatcher::render('{nope} {sender}',
    array('sender' => "Eve\nInjected")) === '{nope} Eve Injected');
check('render: values are not re-expanded', TicketAutomationMatcher::render('{sender}', array('sender' => '{rule}', 'rule' => 'X')) === '{rule}');

// ------------------------------------------------------------------ runner

$wkRule = rule($base + array('trig_collab' => true), 1, 'Customer replied');
resetRunner(array($wkRule));
$t = new Ticket(1, 6);
TicketAutomationRunner::onThreadEntry(msg(1));
check('runner: customer reply moves waiting -> open', $t->status === 1 && $t->statusCalls === 1);
check('runner: one internal note, as SYSTEM, without alert', count($t->notes) === 1 && $t->notes[0]['poster'] === 'SYSTEM'
    && $t->notes[0]['alert'] === false && $t->notes[0]['title'] === 'Ticket Automation');
check('runner: note names rule, trigger, sender and the change', strpos($t->notes[0]['text'], 'Customer replied') !== false
    && strpos($t->notes[0]['text'], 'a message from the customer from Customer') !== false
    && strpos($t->notes[0]['text'], 'Status: Waiting for customer -> Open') !== false, $t->notes[0]['text']);

resetRunner(array($wkRule));
$t = new Ticket(14, 6);
$GLOBALS['fake_prior'] = 0;
TicketAutomationRunner::onThreadEntry(msg(14));
$GLOBALS['fake_prior'] = 1;
check('runner: the message that opens a ticket is no reply (flag not yet set)', $t->status === 6 && !$t->notes);
TicketAutomationRunner::onThreadEntry(msg(14, array('flags' => ThreadEntry::FLAG_ORIGINAL_MESSAGE)));
check('runner: flagged original message is no reply', $t->status === 6 && !$t->notes);

resetRunner(array($wkRule));
$t = new Ticket(2, 6);
TicketAutomationRunner::onThreadEntry(msg(2, array('auto' => true)));
check('runner: bounce or auto-reply changes nothing', $t->status === 6 && !$t->notes);

resetRunner(array($wkRule));
$t = new Ticket(3, 6);
TicketAutomationRunner::onThreadEntry(new ThreadEntry(array('type' => 'R', 'user_id' => 0, 'staff_id' => 4, 'ticket' => 3)));
TicketAutomationRunner::onThreadEntry(new ThreadEntry(array('type' => 'N', 'user_id' => 0, 'staff_id' => 4, 'ticket' => 3)));
check('runner: agent reply and internal note change nothing by default', $t->status === 6 && !$t->notes);

resetRunner(array(rule($base, 1)));
$t = new Ticket(4, 6);
TicketAutomationRunner::onThreadEntry(msg(4, array('user_id' => 11)));
check('runner: collaborator message ignored unless switched on', $t->status === 6 && !$t->notes);

// Loop: a rule on internal notes whose own note would be an agent note.
$noteRule = rule(array('cond_status' => array(6 => 'x', 1 => 'y'), 'trig_user' => false, 'trig_note' => true, 'act_unanswered' => true), 1, 'On notes');
resetRunner(array($noteRule));
$t = new Ticket(5, 6);
$t->nestedEntry = new ThreadEntry(array('type' => 'N', 'user_id' => 0, 'staff_id' => 4, 'ticket' => 5));
TicketAutomationRunner::onThreadEntry(new ThreadEntry(array('type' => 'N', 'user_id' => 0, 'staff_id' => 4, 'ticket' => 5)));
check('loop: an entry created while a rule runs is ignored', count($t->notes) === 1);
TicketAutomationRunner::onThreadEntry(new ThreadEntry(array('type' => 'N', 'user_id' => 0, 'staff_id' => 4, 'ticket' => 5)));
check('loop: the same rule runs once per ticket per request', count($t->notes) === 1);
TicketAutomationRunner::onThreadEntry(new ThreadEntry(array('type' => 'N', 'flags' => ThreadEntry::FLAG_SYSTEM, 'staff_id' => 0, 'ticket' => 5)));
check('loop: system notes never trigger', count($t->notes) === 1);

// Chain: A moves waiting -> open, B watches open. One event, one rule.
resetRunner(array($a, $b));
$t = new Ticket(6, 6);
TicketAutomationRunner::onThreadEntry(msg(6));
check('chain: only the first matching rule runs per event', $t->status === 1 && count($t->notes) === 1);

// Target disabled after the rule was saved.
$disabledLater = rule(array('cond_status' => array(6 => 'x'), 'act_status' => '9'), 1, 'Late disable');
statuses(array(9 => array('Waiting for vendor', 'open', 2)));
resetRunner(array($disabledLater));
$t = new Ticket(7, 6);
TicketAutomationRunner::onThreadEntry(msg(7));
$logs = $GLOBALS['ost']->logs;
check('refused: disabled target at run time changes nothing', $t->status === 6 && $t->statusCalls === 0 && !$t->notes);
check('refused: logged as warning, without mail', count($logs) === 1 && $logs[0][0] === 'warn' && $logs[0][2] === false
    && strpos($logs[0][1], 'disabled') !== false, json_encode($logs));
statuses();

resetRunner(array($wkRule));
$t = new Ticket(8, 6);
$t->failStatus = true;
TicketAutomationRunner::onThreadEntry(msg(8));
check('failed status change: noted in the ticket and logged', $t->status === 6 && count($t->notes) === 1
    && strpos($t->notes[0]['text'], 'Not applied: status (cannot)') !== false && count($GLOBALS['ost']->logs) === 1);

resetRunner(array(rule($base + array('dry_run' => true), 1, 'Dry')));
$t = new Ticket(9, 6);
TicketAutomationRunner::onThreadEntry(msg(9));
check('dry run: nothing changes, the plan is logged', $t->status === 6 && !$t->notes && $t->statusCalls === 0
    && strpos($GLOBALS['ost']->logs[0][1] ?? '', 'would run') !== false
    && strpos($GLOBALS['ost']->logs[0][1] ?? '', 'status Waiting for customer -> Open') !== false);

resetRunner(array($wkRule));
$t = new Ticket(10, 6);
$GLOBALS['thisstaff'] = 'agent-object';
TicketAutomationRunner::onThreadEntry(msg(10));
check('agent context: rule runs as system, agent restored', $t->staffSeen === null && $GLOBALS['thisstaff'] === 'agent-object');
unset($GLOBALS['thisstaff']);

resetRunner(array($wkRule));
$t = new Ticket(11, 6);
$t->throwOnStatus = true;
TicketAutomationRunner::onThreadEntry(msg(11));
$t2 = new Ticket(12, 6);
TicketAutomationRunner::onThreadEntry(msg(12));
check('exception: caught, logged as error, next event still runs', ($GLOBALS['ost']->logs[0][0] ?? '') === 'error'
    && $t2->status === 1 && !array_key_exists('thisstaff', $GLOBALS));

resetRunner(array(rule(array('cond_status' => array(6 => 'x'), 'act_due' => 'clear', 'act_unanswered' => true,
    'act_note' => '{changes} [{due_date}] #{ticket_number}'), 1)));
$t = new Ticket(13, 6);
$t->duedate = '2026-10-30 10:00:00';
$t->isoverdue = 1;
TicketAutomationRunner::onThreadEntry(msg(13));
check('actions: due date cleared, overdue reset, unanswered, template', $t->duedate === null && $t->isoverdue === 0
    && $t->answered === false && $t->notes[0]['text'] === 'Due date cleared; Marked unanswered. [2026-10-30 10:00:00] #100013', $t->notes[0]['text'] ?? '');

// Cron: rule 1 checks the manual due date, rule 2 the SLA date too.
$wv1 = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '1'), 1, 'Manual');
$wv2 = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '6',
    'due_source' => 'manual_or_sla'), 2, 'SLA');
resetRunner(array($wv1, $wv2));
$due = new Ticket(20, 12);
$slaOnly = new Ticket(21, 12);
$notDue = new Ticket(22, 12);
$GLOBALS['fake_due'] = fn(string $sql) => strpos($sql, 'est_duedate <=') !== false ? array(20, 21) : array(20);
TicketAutomationRunner::onCron(null, array('autocron' => false));
check('cron: due ticket goes to the first rule that selected it', $due->status === 1 && count($due->notes) === 1
    && strpos($due->notes[0]['text'], 'the due date from cron') !== false);
check('cron: ticket due only by SLA goes to the SLA rule', $slaOnly->status === 6);
check('cron: ticket not selected stays', $notDue->status === 12 && !$notDue->notes);
check('cron: message rules are not asked', (function () use ($base) {
    resetRunner(array(rule($base, 1)));
    $t = new Ticket(30, 6);
    $GLOBALS['fake_due'] = fn() => array(30);
    TicketAutomationRunner::onCron();
    return $t->status === 6;
})());
check('cron: a ticket that moved on is not selected again', (function () use ($wv1) {
    resetRunner(array($wv1));
    $t = new Ticket(31, 12);
    $GLOBALS['fake_due'] = fn() => array(31);
    TicketAutomationRunner::onCron();
    $first = $t->status;
    // Next cron call, new request: the query no longer returns it because
    // the status left the condition; even if it did, matches() refuses.
    resetRunner(array($wv1));
    Ticket::$all[31] = $t;
    $GLOBALS['fake_due'] = fn() => array(31);
    TicketAutomationRunner::onCron();
    return $first === 1 && $t->status === 1 && count($t->notes) === 1;
})());

// ------------------------------------------------- cron idempotence (A1, A2)

// A1: clearing the due date ends nothing when the SLA due date counts too.
$slaClear = array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true,
    'due_source' => 'manual_or_sla', 'act_due' => 'clear');
check('A1 validate: clear due date + SLA fallback refused, with the reason', strpos($v($slaClear)['trig_due'] ?? '', 'SLA') !== false,
    json_encode($v($slaClear)));
check('A1 validate: clear + SLA fallback valid with a status leaving the condition', $v($slaClear + array('act_status' => '1')) === array());
check('A1 validate: postpone + SLA fallback valid', $v(array('act_due' => 'set', 'act_due_days' => '2') + $slaClear) === array());
check('A1 validate: clear with the ticket due date only stays valid', $v(array('due_source' => 'manual') + $slaClear) === array());

check('due SQL: held-back tickets excluded, integers only', strpos(TicketAutomationMatcher::dueSql($wv1, 't', 's', 100, array(5, '7x', 9)),
    'AND t.ticket_id NOT IN (5,7,9)') !== false && strpos(TicketAutomationMatcher::dueSql($wv1, 't', 's'), 'NOT IN') === false);

// Backoff markers, pure.
$now = 1000000;
$mk = TicketAutomationBackoff::mark(array(), 4, 'fp4', $now);
check('backoff: marker holds while fingerprint and time hold', TicketAutomationBackoff::prune($mk, array(4 => 'fp4'), $now + 10) === $mk);
check('backoff: changed ticket, expired time or deleted ticket lift the marker',
    TicketAutomationBackoff::prune($mk, array(4 => 'other'), $now) === array()
    && TicketAutomationBackoff::prune($mk, array(4 => 'fp4'), $now + TicketAutomationBackoff::SECONDS) === array()
    && TicketAutomationBackoff::prune($mk, array(), $now) === array());
check('backoff: capped, the markers running out first go', (function () use ($now) {
    $m = array();
    for ($i = 1; $i <= TicketAutomationBackoff::MAX + 5; $i++) {
        $m = TicketAutomationBackoff::mark($m, $i, 'f', $now + $i);
    }
    return count($m) === TicketAutomationBackoff::MAX && !isset($m[1]) && isset($m[TicketAutomationBackoff::MAX + 5]);
})());
check('backoff: encode/decode round trip, junk ignored', TicketAutomationBackoff::decode(TicketAutomationBackoff::encode($mk)) === $mk
    && TicketAutomationBackoff::decode('{"x":1,"0":{"fp":"a","until":1},"3":"no"}') === array()
    && TicketAutomationBackoff::decode(null) === array() && TicketAutomationBackoff::encode(array()) === '');

/** Five cron calls, each a request of its own. */
function cronCalls(array $rules, int $n = 5): void
{
    for ($i = 0; $i < $n; $i++) {
        resetRunner($rules, true);
        TicketAutomationRunner::onCron();
    }
}

// A2: target Closed, osTicket refuses. Before 0.3.1: one note per call.
$closeRule = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '3'), 1, 'Close due');
resetRunner(array($closeRule));
$t = new Ticket(40, 12);
$t->failStatus = true;
$GLOBALS['fake_due'] = dueIds(array(40));
cronCalls(array($closeRule));
$logs = $GLOBALS['ost']->logs;
check('A2: refused close, five cron calls, no note', !$t->notes && $t->status === 12, count($t->notes) . ' notes');
check('A2: tried once, logged once with the reason', $t->statusCalls === 1 && count($logs) === 1
    && strpos($logs[0][1], 'nothing changed, not applied: status (cannot)') !== false, json_encode($logs));
check('A2: one marker written, not one per call', $GLOBALS['fake_store']->writes === 1, (string) $GLOBALS['fake_store']->writes);

$t->updated = '2026-11-01 12:30:00';
cronCalls(array($closeRule), 2);
check('A2: ticket changed, tried again once', $t->statusCalls === 2 && count($GLOBALS['ost']->logs) === 2 && !$t->notes);

$GLOBALS['fake_now'] += TicketAutomationBackoff::SECONDS;
$t->failStatus = false;
cronCalls(array($closeRule), 2);
check('A2: after a day tried again without a change, and closed', $t->statusCalls === 3 && $t->status === 3
    && count($t->notes) === 1 && strpos($t->notes[0]['text'], 'Status: Resubmission -> Closed') !== false);

// A held-back ticket does not take a place in the query.
resetRunner(array($closeRule));
$stuck = new Ticket(41, 12);
$stuck->failStatus = true;
$GLOBALS['fake_due'] = dueIds(array(41));
cronCalls(array($closeRule), 1);
$GLOBALS['fake_sql'] = array();
cronCalls(array($closeRule), 1);
check('A2: held-back ticket excluded from the next query', (bool) preg_grep('/NOT IN \(41\)/', $GLOBALS['fake_sql']));

// A partial run (status refused, due date cleared) still changed
// something: note as before, no marker.
$closeClear = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '3',
    'act_due' => 'clear'), 1, 'Close and clear');
resetRunner(array($closeClear));
$t = new Ticket(42, 12);
$t->failStatus = true;
$t->duedate = '2026-10-30 10:00:00';
$GLOBALS['fake_due'] = dueIds(array(42));
cronCalls(array($closeClear), 1);
check('partial run: note with what changed and what not, no marker', count($t->notes) === 1
    && strpos($t->notes[0]['text'], 'Due date cleared. Not applied: status (cannot).') !== false && !$GLOBALS['fake_store']->rows,
    json_encode($t->notes));

// A1 at run time: a rule saved under 0.3.0 with clear + SLA fallback.
$legacy = rule($slaClear, 1, 'SLA clear');
resetRunner(array($legacy));
$t = new Ticket(43, 12);
$t->est = '2026-10-31 10:00:00';
$GLOBALS['fake_due'] = dueIds(array(43));
$GLOBALS['fake_sql'] = array();
cronCalls(array($legacy));
$logs = $GLOBALS['ost']->logs;
check('A1: stored legacy rule, five cron calls, no note, no ticket query', !$t->notes
    && !preg_grep('/^SELECT t\.ticket_id/', $GLOBALS['fake_sql']), count($t->notes) . ' notes');
check('A1: refused rule logged once, not per call or ticket', count($logs) === 1 && strpos($logs[0][1], 'refused for the due date') !== false,
    json_encode($logs));
$GLOBALS['fake_now'] += TicketAutomationBackoff::SECONDS;
cronCalls(array($legacy), 1);
check('A1: logged again after a day', count($GLOBALS['ost']->logs) === 2);

// Dry run on cron: logged once, and switching it off runs at once.
$dry = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '1', 'dry_run' => true), 1, 'Dry due');
resetRunner(array($dry));
$t = new Ticket(44, 12);
$GLOBALS['fake_due'] = dueIds(array(44));
cronCalls(array($dry), 3);
check('dry run on cron: plan logged once, not on every call', count($GLOBALS['ost']->logs) === 1 && $t->status === 12);
$live = rule(array('cond_status' => array(12 => 'x'), 'trig_user' => false, 'trig_due' => true, 'act_status' => '1'), 1, 'Dry due');
cronCalls(array($live), 1);
check('dry run on cron: rule changed, markers dropped, runs at once', $t->status === 1 && count($t->notes) === 1
    && $GLOBALS['fake_store']->rows === array(), json_encode($GLOBALS['fake_store']->rows));

// Markers of a rule that is gone are removed.
resetRunner(array($closeRule));
$GLOBALS['fake_store']->rows = array('rule.9.abc' => '{"5":{"fp":"x","until":1}}', 'refused.9' => '{"fp":"y","until":1}');
$GLOBALS['fake_due'] = dueIds(array());
cronCalls(array($closeRule), 1);
check('store: markers of deleted or edited rules removed', $GLOBALS['fake_store']->rows === array());

// Message triggers keep their behaviour: every run leaves a note.
check('message trigger: failed status still noted (no backoff outside cron)', (function () use ($wkRule) {
    resetRunner(array($wkRule));
    $t = new Ticket(45, 6);
    $t->failStatus = true;
    TicketAutomationRunner::onThreadEntry(msg(45));
    return count($t->notes) === 1 && !$GLOBALS['fake_store']->writes;
})());

// ------------------------------------------------------- bootstrap wiring

resetRunner(array());
Signal::$connected = array();
$inst = new class {
    function getId() { return 7; }
    function getName() { return 'Kunde &amp; Partner'; }
};
$p = new TicketAutomationPlugin();
$cfg = new TicketAutomationConfig();
$cfg->instance = $inst;
$cfg->values = array('cond_status' => array(6 => 'x'), 'act_status' => '1');
$p->config = $cfg;
$p->bootstrap();
$p->bootstrap();
$rules = TicketAutomationRunner::rules();
check('bootstrap: one rule per instance, name decoded', count($rules) === 1 && $rules[0]['id'] === 7 && $rules[0]['name'] === 'Kunde & Partner');
check('bootstrap: signals connected once', count(Signal::$connected) === 2
    && Signal::$connected[0][0] === 'threadentry.created' && Signal::$connected[1][0] === 'cron');
if (is_writable($dir)) {
    touch($dir . '/DISABLED');
    resetRunner(array());
    try {
        $p->bootstrap();
        check('kill switch: DISABLED file stops registration', TicketAutomationRunner::rules() === array());
    } finally {
        unlink($dir . '/DISABLED');
    }
} else {
    // The test stack mounts the plugin read-only; the integration suite has no
    // way to create the file either. Covered by the local run.
    echo "skip kill switch: plugin folder not writable\n";
}

// ------------------------------------------------- passively matched mail

$rm = fn(...$a) => TicketAutomationMatcher::referencedMids(...$a);
check('mids: In-Reply-To first, then References newest first', $rm('<a@x>', '<r1@x> <r2@x>', '') === array('a@x', 'r2@x', 'r1@x'));
check('mids: own Message-ID and duplicates left out', $rm('<a@x>', '<a@x> <own@x>', '', '<own@x>') === array('a@x'));
check('mids: Ref-Mid in the body counts', $rm('', '', "Hello\nRef-Mid: body@x") === array('body@x'));
check('mids: headers without a valid ID give nothing', $rm('nonsense', '<no-at-sign>', 'plain text') === array());

check('rule: passive alert off by default', rule(array())['alert_passive'] === false);
check('rule: passive alert read from the setting', rule(array('act_alert_passive' => true))['alert_passive'] === true);
check('rule: passive alert needs a customer or collaborator trigger', isset($v(array('cond_status' => array(6 => 'x'),
    'trig_user' => false, 'trig_reply' => true, 'act_alert_passive' => true))['act_alert_passive']));
check('rule: passive alert with the customer trigger is valid', $v($base + array('act_alert_passive' => true)) === array());

// Detector against the fake email table.
$GLOBALS['fake_mids'] = array('<first@customer.example>' => 301, '<new@customer.example>' => 302);
$GLOBALS['fake_threads'] = array(290 => 1003);
$fwd = msg(3, array('id' => 302, 'mid' => '<new@customer.example>',
    'headers' => array('Subject' => 'Fwd: Question', 'References' => '<first@customer.example>')));
check('passive: forward that only refers to the customer\'s own first mail', TicketAutomationPassive::isPassive($fwd));
$own = msg(3, array('id' => 302, 'mid' => '<new@customer.example>', 'headers' => array('In-Reply-To' => '<new@customer.example>')));
check('passive: a reference to the message itself does not count', !TicketAutomationPassive::isPassive($own));
\osTicket\Mail\Mailer::$decoded = array('desk@helpdesk.example' => array('loopback' => true, 'uid' => 'u', 'entryId' => 290, 'threadId' => 1003));
$reply = msg(3, array('id' => 302, 'mid' => '<new@customer.example>',
    'headers' => array('In-Reply-To' => '<desk@helpdesk.example>', 'References' => '<first@customer.example> <desk@helpdesk.example>')));
check('passive: a reply to a mail the help desk sent is a positive match', !TicketAutomationPassive::isPassive($reply));
\osTicket\Mail\Mailer::$decoded = array('desk@helpdesk.example' => array('loopback' => true, 'uid' => 'u', 'entryId' => 290, 'threadId' => 9999));
check('passive: help-desk ID of another thread is no positive match', TicketAutomationPassive::isPassive($reply));
\osTicket\Mail\Mailer::$decoded = array();
check('passive: web or API message without mail headers', !TicketAutomationPassive::isPassive(msg(3, array('id' => 303))));
check('passive: unknown references only (osTicket would have opened a new ticket)', !TicketAutomationPassive::isPassive(
    msg(3, array('id' => 302, 'headers' => array('References' => '<elsewhere@other.example>')))));
check('passive: agent reply is never passive', !TicketAutomationPassive::isPassive(new ThreadEntry(array('type' => 'R', 'ticket' => 3,
    'id' => 302, 'headers' => array('References' => '<first@customer.example>')))));
$GLOBALS['fake_mids'] = array();
$GLOBALS['fake_threads'] = array();

// Runner with the seams replaced.
$passiveCalls = $alertCalls = 0;
$passiveAnswer = true;
$alertAnswer = 1;
TicketAutomationRunner::$passiveCheck = function () use (&$passiveCalls, &$passiveAnswer) { $passiveCalls++; return $passiveAnswer; };
TicketAutomationRunner::$alerter = function () use (&$alertCalls, &$alertAnswer) { $alertCalls++; return $alertAnswer; };
$alertRule = rule($base + array('act_alert_passive' => true, 'trig_collab' => true), 1, 'Customer replied');

resetRunner(array($alertRule));
$t = new Ticket(40, 6);
TicketAutomationRunner::onThreadEntry(msg(40));
check('runner: passive mail -> status changed and agents alerted once', $t->status === 1 && $alertCalls === 1);
check('runner: the note reports the alert', count($t->notes) === 1
    && strpos($t->notes[0]['text'], 'osTicket sent no alert: "New Message" alert sent to 1 agent(s)') !== false, $t->notes[0]['text'] ?? '');

$alertCalls = 0;
$passiveAnswer = false;
resetRunner(array($alertRule));
$t = new Ticket(41, 6);
TicketAutomationRunner::onThreadEntry(msg(41));
check('runner: positively matched mail -> no extra alert (osTicket sends its own)', $t->status === 1 && $alertCalls === 0
    && strpos($t->notes[0]['text'], 'alert') === false);

$alertCalls = $passiveCalls = 0;
$passiveAnswer = true;
resetRunner(array(rule($base, 1)));
$t = new Ticket(42, 6);
TicketAutomationRunner::onThreadEntry(msg(42));
check('runner: option off -> not even checked', $passiveCalls === 0 && $alertCalls === 0);

resetRunner(array(rule($base + array('act_alert_passive' => true, 'skip_auto' => false), 1)));
$t = new Ticket(43, 6);
TicketAutomationRunner::onThreadEntry(msg(43, array('auto' => true)));
check('runner: auto-reply never alerts, even when the rule runs on it', $t->status === 1 && $alertCalls === 0);

resetRunner(array(rule($base + array('act_alert_passive' => true, 'trig_collab' => true), 1)));
$t = new Ticket(44, 6);
TicketAutomationRunner::onThreadEntry(msg(44, array('user_id' => 11)));
check('runner: collaborator mail matched passively alerts too', $alertCalls === 1);

$alertCalls = 0;
resetRunner(array(rule($base + array('act_alert_passive' => true, 'dry_run' => true), 1)));
$t = new Ticket(45, 6);
TicketAutomationRunner::onThreadEntry(msg(45));
$logs = $GLOBALS['ost']->logs;
check('dry run: alert only reported, not sent', $alertCalls === 0 && $t->status === 6 && count($logs) === 1
    && strpos($logs[0][1], 'New Message alert to agents') !== false, json_encode($logs));

$alertAnswer = 0;
resetRunner(array($alertRule));
$t = new Ticket(46, 6);
TicketAutomationRunner::onThreadEntry(msg(46));
$logs = $GLOBALS['ost']->logs;
check('runner: nobody to alert -> logged as warning, rule still done', $t->status === 1 && count($t->notes) === 1
    && count($logs) === 1 && strpos($logs[0][1], 'no agent could be alerted') !== false, json_encode($logs));

$alertCalls = 0;
$alertAnswer = 1;
resetRunner(array($alertRule));
$t = new Ticket(47, 1);
TicketAutomationRunner::onThreadEntry(msg(47));
check('runner: ticket outside the rule\'s statuses -> no alert (rule does not run)', $alertCalls === 0 && !$t->notes);

// ------------------------------------- condition: only passively matched mail

$cat = statuses();
check('passive-only: closed status allowed as condition', $v(array('cond_status' => array(3 => 'x'), 'cond_passive' => true,
    'act_status' => '1', 'act_alert_passive' => true)) === array(), json_encode($v(array('cond_status' => array(3 => 'x'), 'cond_passive' => true, 'act_status' => '1'))));
check('passive-only: closed status without it still refused', isset($v(array('cond_status' => array(3 => 'x'), 'act_status' => '1'))['cond_status']));
check('passive-only: needs a customer or collaborator trigger', isset($v(array('cond_status' => array(1 => 'x'),
    'trig_user' => false, 'trig_reply' => true, 'cond_passive' => true))['cond_passive']));
check('passive-only: not together with the due trigger', isset($v(array('cond_status' => array(1 => 'x'),
    'trig_due' => true, 'act_due' => 'clear', 'cond_passive' => true))['cond_passive']));
check('passive-only: archived status still refused', isset($v(array('cond_status' => array(5 => 'x'), 'cond_passive' => true))['cond_status']));
$choices = TicketAutomationCatalog::conditionChoices();
check('picker: closed statuses listed after the open ones, marked', isset($choices['3'])
    && strpos($choices['3'], 'passive mail only') !== false && array_search(3, array_keys($choices), true) > array_search(1, array_keys($choices), true)
    && !isset($choices['5']), json_encode($choices));

$po = rule(array('cond_status' => array(1 => 'x', 3 => 'y'), 'cond_passive' => true, 'trig_collab' => true), 1);
$mm = fn(array $t) => TicketAutomationMatcher::matches($po, 'user_message', $t);
check('match: passive-only rule ignores a normal reply', !$mm(array('status_id' => 1, 'status_state' => 'open', 'passive' => false)));
check('match: passive-only rule takes a passive mail', $mm(array('status_id' => 1, 'status_state' => 'open', 'passive' => true)));
check('match: passive-only rule takes a closed ticket', $mm(array('status_id' => 3, 'status_state' => 'closed', 'passive' => true)));
check('match: ordinary rule never takes a closed ticket', !TicketAutomationMatcher::matches(rule(array('cond_status' => array(3 => 'x'))), 'user_message',
    array('status_id' => 3, 'status_state' => 'closed', 'passive' => true)));

// Catch-all after the usual rule: open and closed, passive only, reopen.
$catchAll = rule(array('cond_status' => array(1 => 'x', 3 => 'y'), 'cond_passive' => true, 'trig_collab' => true,
    'act_status' => '1', 'act_alert_passive' => true), 2, 'Passive mail');
$alertCalls = $passiveCalls = 0;
$passiveAnswer = true;
$alertAnswer = 1;
resetRunner(array($alertRule, $catchAll));
$t = new Ticket(50, 3);
TicketAutomationRunner::onThreadEntry(msg(50));
check('closed + passive: reopened to Open, agents alerted, one note', $t->status === 1 && $alertCalls === 1 && count($t->notes) === 1
    && strpos($t->notes[0]['text'], 'Status: Closed -> Open') !== false, json_encode($t->notes));

$alertCalls = 0;
resetRunner(array($alertRule, $catchAll));
$t = new Ticket(51, 1);
TicketAutomationRunner::onThreadEntry(msg(51));
check('open + passive: catch-all alerts, status stays', $t->status === 1 && $alertCalls === 1 && count($t->notes) === 1);

$alertCalls = $passiveCalls = 0;
$passiveAnswer = false;
resetRunner(array($alertRule, $catchAll));
$t = new Ticket(52, 1);
TicketAutomationRunner::onThreadEntry(msg(52));
check('open + normal reply: catch-all stays out, no note (osTicket alerts itself)', $passiveCalls === 1 && $alertCalls === 0 && !$t->notes);

resetRunner(array($alertRule, $catchAll));
$t = new Ticket(53, 6);
TicketAutomationRunner::onThreadEntry(msg(53));
check('waiting + normal reply: the usual rule still runs first', $t->status === 1 && count($t->notes) === 1 && $alertCalls === 0);

$passiveCalls = 0;
resetRunner(array($alertRule, $catchAll));
$t = new Ticket(54, 3);
TicketAutomationRunner::onThreadEntry(msg(54, array('auto' => true)));
check('closed + auto-reply: not even checked, ticket stays closed', $passiveCalls === 0 && $t->status === 3 && !$t->notes);

TicketAutomationRunner::$passiveCheck = array('TicketAutomationPassive', 'isPassive');
TicketAutomationRunner::$alerter = array('TicketAutomationPassive', 'newMessageAlert');

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
