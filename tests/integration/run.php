<?php
/**
 * Integration suite against the real osTicket 1.18.4 of dev/up.sh.
 *
 *   ./dev/test.sh                 (unit tests, then this)
 *   docker compose ... exec osticket php /tests/integration/run.php
 *
 * Rules are written to the plugin's instance tables the way the admin panel
 * stores them. Every event runs in a fresh PHP process (step.php, or osTicket's
 * own api/pipe.php and api/cron.php), so each one bootstraps the plugin from
 * the database like a real request. This process only reads and prepares
 * data with SQL.
 *
 * How mail is simulated:
 *   - Ticket::postMessage() with userId, as the client portal and the mail
 *     fetcher call it; with raw mail headers and a Message-Id for the
 *     bounce and auto-reply cases (ThreadEntry::isBounceOrAutoReply() reads
 *     the stored headers).
 *   - A raw RFC 822 message piped into api/pipe.php, osTicket's own mail
 *     entry point: parsing, thread matching by "[#number]" in the subject,
 *     postMessage().
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
$_SERVER['SCRIPT_NAME'] = '/run.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require 'main.inc.php';
require_once INCLUDE_DIR . 'plugins/ticket-automation/src/TicketAutomationRule.php';

$seed = json_decode((string) file_get_contents('/var/www/html/include/plugins/ticket-automation/dev/local/seed.json'), true);
if (!$seed) {
    fwrite(STDERR, "run: dev/local/seed.json missing, run dev/up.sh\n");
    exit(2);
}
$S = $seed['status'];
$PID = (int) $seed['plugin'];
$ALICE = (int) $seed['users']['alice'];
$BOB = (int) $seed['users']['bob'];
$ADMIN = (int) $seed['staff']['admin'];
$DEPT = (int) $seed['dept']['support'];

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . (!$ok && $detail !== '' ? ": $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function q(string $sql): void
{
    if (!db_query($sql)) {
        fwrite(STDERR, "run: query failed: $sql\n");
        exit(2);
    }
}

function scalar(string $sql)
{
    $res = db_query($sql);
    $row = $res ? db_fetch_row($res) : null;
    return $row ? $row[0] : null;
}

function step(array $in): array
{
    $out = shell_exec('php /tests/integration/step.php ' . escapeshellarg(json_encode($in)) . ' 2>&1');
    $data = json_decode(trim((string) $out), true);
    if (!is_array($data)) {
        fwrite(STDERR, "run: step failed: " . json_encode($in) . "\n$out\n");
        exit(2);
    }
    return $data;
}

function cron(): void
{
    shell_exec('cd /var/www/html/api && php cron.php 2>&1');
}

function pipe(string $raw): int
{
    $file = tempnam(sys_get_temp_dir(), 'eml');
    file_put_contents($file, $raw);
    exec('cd /var/www/html/api && php pipe.php < ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
    unlink($file);
    return $code;
}

/**
 * Replaces all rules. $rules: name => settings in config-table format
 * (multiselect as JSON id => label, booleans '1'/'0').
 */
function rules(array $rules): array
{
    global $PID;
    q(sprintf("DELETE FROM %s WHERE namespace LIKE 'plugin.%d.instance.%%'", CONFIG_TABLE, $PID));
    q(sprintf("DELETE FROM %s WHERE plugin_id=%d", PLUGIN_INSTANCE_TABLE, $PID));
    $ids = array();
    foreach ($rules as $name => $values) {
        q(sprintf("INSERT INTO %s (plugin_id, flags, name, notes, created, updated) VALUES (%d, 1, %s, '', NOW(), NOW())",
            PLUGIN_INSTANCE_TABLE, $PID, db_input($name)));
        $iid = (int) db_insert_id();
        $ids[$name] = $iid;
        foreach ($values as $k => $v) {
            q(sprintf("INSERT INTO %s (namespace, `key`, value, updated) VALUES (%s, %s, %s, NOW())", CONFIG_TABLE,
                db_input(sprintf('plugin.%d.instance.%d', $PID, $iid)), db_input($k), db_input((string) $v)));
        }
    }
    return $ids;
}

function sel(array $ids): string
{
    $out = array();
    foreach ($ids as $id) {
        $out[(string) $id] = 'x';
    }
    return json_encode($out, JSON_FORCE_OBJECT);
}

function ticket(int $status, array $extra = array()): array
{
    global $DEPT;
    return step($extra + array('action' => 'create', 'email' => 'alice@example.com', 'name' => 'Alice Customer',
        'dept' => $DEPT, 'status' => $status));
}

function statusOf(int $tid): int
{
    return (int) scalar(sprintf('SELECT status_id FROM %s WHERE ticket_id=%d', TICKET_TABLE, $tid));
}

function col(int $tid, string $col)
{
    return scalar(sprintf('SELECT %s FROM %s WHERE ticket_id=%d', $col, TICKET_TABLE, $tid));
}

function autoNotes(int $tid): array
{
    $res = db_query(sprintf("SELECT e.body, e.poster, e.flags FROM %s e JOIN %s th ON th.id=e.thread_id
        WHERE th.object_type='T' AND th.object_id=%d AND e.type='N' AND e.title='Ticket Automation' ORDER BY e.id",
        THREAD_ENTRY_TABLE, THREAD_TABLE, $tid));
    $out = array();
    while ($res && ($row = db_fetch_array($res))) {
        $row["body"] = html_entity_decode((string) $row["body"], ENT_QUOTES, "UTF-8");
        $out[] = $row;
    }
    return $out;
}

function entries(int $tid): int
{
    return (int) scalar(sprintf("SELECT COUNT(*) FROM %s e JOIN %s th ON th.id=e.thread_id
        WHERE th.object_type='T' AND th.object_id=%d", THREAD_ENTRY_TABLE, THREAD_TABLE, $tid));
}

/** Messages Mailpit has received so far (msmtp delivers synchronously). */
function mails(): int
{
    $j = json_decode((string) @file_get_contents('http://mail:8025/api/v1/messages?limit=1'), true);
    return is_array($j) ? (int) $j['total'] : -1;
}

function syslogCount(string $like): int
{
    return (int) scalar(sprintf("SELECT COUNT(*) FROM %s WHERE title='Ticket Automation' AND log LIKE %s",
        SYSLOG_TABLE, db_input('%' . $like . '%')));
}

// Rules of the README examples, in the instance order the admin panel keeps.
$replyRule = array(
    'trig_user' => '1', 'trig_collab' => '0',
    'cond_status' => sel(array($S['wait_customer'], $S['wait_vendor'])),
    'skip_auto' => '1',
    'act_status' => sel(array($S['open'])),
    'act_note' => 'Rule "{rule}": {old_status} -> {new_status} after {trigger} from {sender}.',
);
$dueRule = array(
    'trig_user' => '0', 'trig_due' => '1', 'due_source' => 'manual',
    'cond_status' => sel(array($S['resubmission'])),
    'act_status' => sel(array($S['open'])),
);

// -------------------------------------------------------- customer message

$ids = rules(array('Customer replied' => $replyRule, 'Resubmission due' => $dueRule));

$t = ticket($S['wait_customer']);
$mailsBefore = mails();
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
$notes = autoNotes($t['id']);
check('customer message: waiting for customer -> open', statusOf($t['id']) === $S['open'], (string) statusOf($t['id']));
check('customer message: one internal note with the filled template', count($notes) === 1
    && strpos($notes[0]['body'], 'Rule "Customer replied": Waiting for customer -> Open after a message from the customer from Alice Customer.') !== false,
    json_encode($notes));
check('customer message: note posted by SYSTEM as a system entry', count($notes) === 1 && $notes[0]['poster'] === 'SYSTEM'
    && ((int) $notes[0]['flags'] & ThreadEntry::FLAG_SYSTEM) !== 0);
check('customer message: osTicket still marks it unanswered afterwards', (int) col($t['id'], 'isanswered') === 0);
check('customer message: the rule sends no mail', $mailsBefore >= 0 && mails() === $mailsBefore, "$mailsBefore -> " . mails());

$t = ticket($S['wait_vendor']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('customer message: waiting for vendor -> open', statusOf($t['id']) === $S['open']);

$t = ticket($S['open']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('customer message in another status: no change, no note', statusOf($t['id']) === $S['open'] && !autoNotes($t['id']));

$t = ticket($S['closed']);
q(sprintf('UPDATE %s SET closed=NOW() WHERE ticket_id=%d', TICKET_TABLE, $t['id']));
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('closed ticket: osTicket reopens it, the rule stays out', statusOf($t['id']) === $S['open'] && !autoNotes($t['id']));

// Real mail path: raw message into api/pipe.php, matched by ticket number.
$t = ticket($S['wait_customer']);
$code = pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\n"
    . "Subject: Re: [#{$t['number']}] Integration ticket\r\nMessage-ID: <pipe-" . bin2hex(random_bytes(6)) . "@example.com>\r\n"
    . "Date: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nReply by mail.\r\n");
check('customer mail through api/pipe.php: waiting -> open', $code === 0 && statusOf($t['id']) === $S['open'] && count(autoNotes($t['id'])) === 1,
    "exit $code, status " . statusOf($t['id']));

$t = ticket($S['wait_customer']);
$code = pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\n"
    . "Subject: Re: [#{$t['number']}] Out of office\r\nMessage-ID: <ooo-" . bin2hex(random_bytes(6)) . "@example.com>\r\n"
    . "Auto-Submitted: auto-replied\r\nDate: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nI am away.\r\n");
check('auto-reply mail through api/pipe.php: no change', $code === 0 && statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']),
    "exit $code, entries " . entries($t['id']));

// ---------------------------------------------------- agent, note, bounces

$t = ticket($S['wait_customer']);
$r = step(array('action' => 'reply', 'ticket' => $t['id'], 'staff' => $ADMIN));
check('agent reply: no change', $r['entry'] > 0 && statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']), json_encode($r));

$t = ticket($S['wait_customer']);
$r = step(array('action' => 'note', 'ticket' => $t['id'], 'staff' => $ADMIN));
check('internal note: no change', $r['entry'] > 0 && statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));

$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE,
    'header' => "From: Alice Customer <alice@example.com>\r\nSubject: Automatic reply\r\nAuto-Submitted: auto-replied\r\n"));
check('auto-reply message: no change', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));

$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE,
    'header' => "From: Mail Delivery System <MAILER-DAEMON@example.com>\r\nSubject: Undelivered Mail Returned to Sender\r\n"
              . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"x\"\r\n"));
check('bounce message: no change', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));

$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE, 'header' => "From: Alice Customer <alice@example.com>\r\nSubject: Re: thanks\r\n"));
check('plain mail with headers still counts', statusOf($t['id']) === $S['open']);

// ------------------------------------------------------------ collaborator

$t = ticket($S['wait_customer']);
step(array('action' => 'collab', 'ticket' => $t['id'], 'user' => $BOB));
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $BOB));
check('collaborator message, trigger off: no change', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));

rules(array('Customer replied' => array('trig_collab' => '1') + $replyRule, 'Resubmission due' => $dueRule));
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $BOB, 'text' => 'Second message from the colleague.'));
$notes = autoNotes($t['id']);
check('collaborator message, trigger on: waiting -> open', statusOf($t['id']) === $S['open'] && count($notes) === 1
    && strpos($notes[0]['body'], 'a message from a collaborator from Bob Colleague') !== false, json_encode($notes));

// ------------------------------------------------------------------- cron

$ids = rules(array('Customer replied' => $replyRule, 'Resubmission due' => $dueRule));
$dueT = ticket($S['resubmission'], array('due_hours' => -2));
$futureT = ticket($S['resubmission'], array('due_hours' => 30));
$noDueT = ticket($S['resubmission']);
$otherT = ticket($S['wait_customer'], array('due_hours' => -2));
$mailsBefore = mails();
cron();
check('cron: due resubmission -> open, with note', statusOf($dueT['id']) === $S['open'] && count(autoNotes($dueT['id'])) === 1
    && strpos(autoNotes($dueT['id'])[0]['body'], 'the due date from cron') !== false, json_encode(autoNotes($dueT['id'])));
check('cron: the rule sends no mail', mails() === $mailsBefore, "$mailsBefore -> " . mails());
check('cron: resubmission not yet due stays', statusOf($futureT['id']) === $S['resubmission'] && !autoNotes($futureT['id']));
check('cron: resubmission without due date stays', statusOf($noDueT['id']) === $S['resubmission'] && !autoNotes($noDueT['id']));
check('cron: due ticket in another status stays', statusOf($otherT['id']) === $S['wait_customer'] && !autoNotes($otherT['id']));
cron();
check('cron: a second run changes nothing more', count(autoNotes($dueT['id'])) === 1 && statusOf($dueT['id']) === $S['open']);

// Clear the due date as well, for the README example's variant.
rules(array('Resubmission due' => array('act_due' => 'clear') + $dueRule));
$t = ticket($S['resubmission'], array('due_hours' => -2));
cron();
check('cron: due date cleared when the rule says so', statusOf($t['id']) === $S['open'] && col($t['id'], 'duedate') === null
    && (int) col($t['id'], 'isoverdue') === 0);

// ------------------------------------------- cron idempotence (loop PoCs)
// Five cron calls in a row. A run that changes nothing writes no note and
// is not tried again until the ticket changes; before 0.3.1 every call
// wrote one note, without end.

// A1: "clear due date" with the SLA due date as fallback. The rule is
// stored the way 0.3.0 allowed it; the form refuses it now.
rules(array('SLA clear' => array('due_source' => 'manual_or_sla', 'act_due' => 'clear',
    'act_status' => '') + $dueRule));
$t = ticket($S['resubmission']);
q(sprintf('UPDATE %s SET duedate=NULL, est_duedate=NOW() - INTERVAL 2 HOUR WHERE ticket_id=%d', TICKET_TABLE, $t['id']));
$logsBefore = syslogCount('SLA clear');
for ($i = 0; $i < 5; $i++) {
    cron();
}
check('loop A1: clear due date + SLA fallback, five cron calls, no note', count(autoNotes($t['id'])) === 0,
    count(autoNotes($t['id'])) . ' notes');
check('loop A1: the refused rule is logged once, not on every call', syslogCount('SLA clear') - $logsBefore === 1,
    (string) (syslogCount('SLA clear') - $logsBefore));

// A2: target "Closed", but osTicket refuses to close (help topic required).
$requireTopic = scalar(sprintf("SELECT value FROM %s WHERE namespace='core' AND `key`='require_topic_to_close'", CONFIG_TABLE));
$setRequireTopic = static function (string $v): void {
    q(sprintf("INSERT INTO %s (namespace, `key`, value, updated) VALUES ('core', 'require_topic_to_close', %s, NOW())"
        . " ON DUPLICATE KEY UPDATE value=VALUES(value)", CONFIG_TABLE, db_input($v)));
};
$setRequireTopic('1');
rules(array('Close due' => array('act_status' => sel(array($S['closed']))) + $dueRule));
$t = ticket($S['resubmission'], array('due_hours' => -2));
q(sprintf('UPDATE %s SET topic_id=0 WHERE ticket_id=%d', TICKET_TABLE, $t['id']));
$logsBefore = syslogCount('Close due');
for ($i = 0; $i < 5; $i++) {
    cron();
}
check('loop A2: refused close, five cron calls, no note', count(autoNotes($t['id'])) === 0 && statusOf($t['id']) === $S['resubmission'],
    count(autoNotes($t['id'])) . ' notes, status ' . statusOf($t['id']));
check('loop A2: logged once, with osTicket\'s reason', syslogCount('Close due') - $logsBefore === 1
    && syslogCount('Help Topic') >= 1, (string) (syslogCount('Close due') - $logsBefore));

// The ticket changes: the rule tries again on the next call and closes it.
$topic = (int) scalar(sprintf('SELECT topic_id FROM %s ORDER BY topic_id LIMIT 1', TOPIC_TABLE));
q(sprintf('UPDATE %s SET topic_id=%d, updated=NOW() + INTERVAL 1 MINUTE WHERE ticket_id=%d', TICKET_TABLE, $topic, $t['id']));
cron();
check('loop A2: after the ticket changed, the next call runs the rule', $topic > 0 && statusOf($t['id']) === $S['closed']
    && count(autoNotes($t['id'])) === 1, 'topic ' . $topic . ', status ' . statusOf($t['id']));
$setRequireTopic((string) ($requireTopic ?? '0'));

// --------------------------------------------------- disabled target status

$ids = rules(array('Customer replied' => $replyRule));
$save = step(array('action' => 'save', 'plugin' => $PID, 'instance' => $ids['Customer replied'], 'values' => array(
    'cond_status' => array((string) $S['wait_customer']), 'act_status' => (string) $S['parked'], 'trig_user' => '1')));
$stored = (string) scalar(sprintf("SELECT value FROM %s WHERE namespace=%s AND `key`='act_status'", CONFIG_TABLE,
    db_input(sprintf('plugin.%d.instance.%d', $PID, $ids['Customer replied']))));
check('save: disabled target status refused by the form', !$save['ok'] && strpos($save['errors']['act_status'] ?? '', 'disabled') !== false && strpos($stored, (string) $S['parked']) === false,
    json_encode($save) . ' stored ' . $stored);

$save = step(array('action' => 'save', 'plugin' => $PID, 'instance' => $ids['Customer replied'], 'values' => array(
    'cond_status' => array((string) $S['wait_customer']), 'act_status' => (string) $S['closed'], 'trig_user' => '1')));
check('save: closed target with a message trigger refused', !$save['ok'] && strpos($save['errors']['act_status'] ?? '', 'closed status') !== false, json_encode($save));

$save = step(array('action' => 'save', 'plugin' => $PID, 'instance' => $ids['Customer replied'], 'values' => array(
    'cond_status' => array((string) $S['wait_customer']), 'act_status' => (string) $S['wait_vendor'], 'trig_user' => '1')));
check('save: valid rule accepted and stored', $save['ok'] && $save['errors'] === array() && TicketAutomationRule::id((string) scalar(sprintf("SELECT value FROM %s WHERE namespace=%s AND `key`='act_status'", CONFIG_TABLE, db_input(sprintf('plugin.%d.instance.%d', $PID, $ids['Customer replied']))))) === $S['wait_vendor'], json_encode($save));

// Saved while enabled, then disabled in the status list.
q(sprintf('UPDATE %s SET mode=2 WHERE id=%d', TICKET_STATUS_TABLE, $S['wait_vendor']));
$before = syslogCount('disabled in the status list');
$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('run time: target disabled after saving is refused', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));
check('run time: refusal in the system log', syslogCount('disabled in the status list') === $before + 1);
q(sprintf('UPDATE %s SET mode=3 WHERE id=%d', TICKET_STATUS_TABLE, $S['wait_vendor']));

// --------------------------------------------------- priority and due date

$high = (int) scalar(sprintf("SELECT priority_id FROM %s WHERE priority='high'", PRIORITY_TABLE));
rules(array('Escalate' => array('act_priority' => sel(array($high)), 'act_due' => 'set', 'act_due_days' => '2', 'act_note' => '') + $replyRule));
$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
$prio = (int) scalar(sprintf('SELECT priority FROM %sticket__cdata WHERE ticket_id=%d', TABLE_PREFIX, $t['id']));
$dueIn = (int) scalar(sprintf('SELECT TIMESTAMPDIFF(HOUR, NOW(), duedate) FROM %s WHERE ticket_id=%d', TICKET_TABLE, $t['id']));
$notes = autoNotes($t['id']);
check('actions: priority set through the core field form', $prio === $high, "priority $prio, expected $high");
check('actions: due date set two days ahead', $dueIn >= 47 && $dueIn <= 48, "hours $dueIn");
check('actions: default note lists every change', count($notes) === 1 && strpos($notes[0]['body'], 'Status: Waiting for customer -> Open; Priority changed; Due date set to ') !== false,
    json_encode($notes));

// ------------------------------------------------------------ new tickets

// The opening message is posted before osTicket flags it as original.
rules(array('Open watcher' => array('cond_status' => sel(array($S['open'])), 'act_status' => sel(array($S['wait_customer'])))));
$t = ticket($S['open']);
check('new ticket: its opening message does not run a rule', statusOf($t['id']) === $S['open'] && !autoNotes($t['id']));
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('new ticket: the first reply does', statusOf($t['id']) === $S['wait_customer'] && count(autoNotes($t['id'])) === 1);

// ------------------------------------------------------------- loops, chains

// Ping-pong: A sends waiting -> open, B sends open -> waiting, both on
// customer messages. One message must run exactly one of them.
rules(array(
    'A' => $replyRule,
    'B' => array('cond_status' => sel(array($S['open'])), 'act_status' => sel(array($S['wait_customer']))),
));
$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('chain: one message runs one rule', statusOf($t['id']) === $S['open'] && count(autoNotes($t['id'])) === 1,
    'status ' . statusOf($t['id']) . ' notes ' . json_encode(autoNotes($t['id'])));

// A rule on internal notes: its own note is an internal note too.
rules(array('On notes' => array('trig_user' => '0', 'trig_note' => '1', 'trig_reply' => '1',
    'cond_status' => sel(array($S['wait_customer'], $S['open'])), 'act_unanswered' => '1')));
$t = ticket($S['wait_customer']);
$before = entries($t['id']);
step(array('action' => 'note', 'ticket' => $t['id'], 'staff' => $ADMIN));
check('loop: agent note runs the rule once, its own note does not', count(autoNotes($t['id'])) === 1
    && entries($t['id']) === $before + 2 && (int) col($t['id'], 'isanswered') === 0, 'entries ' . entries($t['id']));
step(array('action' => 'reply', 'ticket' => $t['id'], 'staff' => $ADMIN));
// The ticket is unanswered already: the rule has nothing to change, so
// the reply gets no automation note.
check('loop: agent reply with nothing to change, no note, no cascade', count(autoNotes($t['id'])) === 1
    && entries($t['id']) === $before + 3, 'entries ' . entries($t['id']) . ', notes ' . count(autoNotes($t['id'])));

// -------------------------------------------------------------- dry run

rules(array('Dry' => array('dry_run' => '1') + $replyRule));
$t = ticket($S['wait_customer']);
$before = syslogCount('Dry run: rule "Dry"');
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('dry run: no change, no note, plan in the system log', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id'])
    && syslogCount('Dry run: rule "Dry"') === $before + 1);

// ----------------------------------------- passively matched mail (forward)

/** Subject and recipients of the newest mail in Mailpit. */
function lastMail(): array
{
    $j = json_decode((string) @file_get_contents('http://mail:8025/api/v1/messages?limit=1'), true);
    $m = $j['messages'][0] ?? array();
    return array('subject' => (string) ($m['Subject'] ?? ''),
        'to' => implode(',', array_map(fn($a) => (string) ($a['Address'] ?? ''), $m['To'] ?? array())));
}

function ticketByMid(string $mid): int
{
    return (int) scalar(sprintf("SELECT th.object_id FROM %s em JOIN %s e ON e.id=em.thread_entry_id
        JOIN %s th ON th.id=e.thread_id WHERE em.mid=%s", THREAD_ENTRY_EMAIL_TABLE, THREAD_ENTRY_TABLE, THREAD_TABLE, db_input($mid)));
}

// The note template lists {changes}, which is where the alert is reported.
rules(array('Customer replied' => array('act_alert_passive' => '1', 'act_note' => 'Rule "{rule}": {changes}') + $replyRule));
$adminMail = (string) scalar(sprintf('SELECT email FROM %s WHERE staff_id=%d', STAFF_TABLE, $ADMIN));

// The customer writes, the agent puts the ticket on "waiting", the customer
// forwards their own first mail instead of answering the agent.
$orig = '<orig-' . bin2hex(random_bytes(6)) . '@example.com>';
$code = pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Press mailbox\r\n"
    . "Message-ID: $orig\r\nDate: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nIs there a press address?\r\n");
$tid = ticketByMid($orig);
q(sprintf('UPDATE %s SET status_id=%d, staff_id=%d WHERE ticket_id=%d', TICKET_TABLE, $S['wait_customer'], $ADMIN, $tid));
$mailsBefore = mails();
$code2 = pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Fwd: Press mailbox\r\n"
    . "Message-ID: <fwd-" . bin2hex(random_bytes(6)) . "@example.com>\r\nReferences: $orig\r\n"
    . "Date: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nStill need it.\r\n\r\n---------- Forwarded ----------\r\nIs there a press address?\r\n");
$notes = autoNotes($tid);
$last = lastMail();
check('passive forward: added to the ticket, status waiting -> open', $code === 0 && $code2 === 0 && $tid > 0
    && statusOf($tid) === $S['open'], "code $code/$code2 ticket $tid status " . statusOf($tid));
check('passive forward: exactly one alert mail, to the assigned agent', mails() === $mailsBefore + 1
    && stripos($last['to'], $adminMail) !== false, "$mailsBefore -> " . mails() . ' ' . json_encode($last));
check('passive forward: note reports the alert', count($notes) === 1
    && strpos($notes[0]['body'], '"New Message" alert sent to 1 agent(s)') !== false, json_encode($notes));

// Control: a real reply (ticket number in the subject) is matched positively;
// osTicket alerts itself and the rule must not add a second mail.
q(sprintf('UPDATE %s SET status_id=%d WHERE ticket_id=%d', TICKET_TABLE, $S['wait_customer'], $tid));
$number = (string) col($tid, 'number');
$mailsBefore = mails();
$code = pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Re: [#$number] Press mailbox\r\n"
    . "Message-ID: <re-" . bin2hex(random_bytes(6)) . "@example.com>\r\nDate: " . date('r')
    . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAnother reply.\r\n");
$notes = autoNotes($tid);
check('positive reply: osTicket\'s own alert only, no second mail', $code === 0 && mails() === $mailsBefore + 1
    && count($notes) === 2 && strpos($notes[1]['body'], 'alert sent') === false, "$mailsBefore -> " . mails() . ' ' . json_encode($notes));

// Option off: the forward stays silent, as in osTicket itself.
rules(array('Customer replied' => $replyRule));
q(sprintf('UPDATE %s SET status_id=%d WHERE ticket_id=%d', TICKET_TABLE, $S['wait_customer'], $tid));
$mailsBefore = mails();
pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Fwd: Press mailbox\r\n"
    . "Message-ID: <fwd2-" . bin2hex(random_bytes(6)) . "@example.com>\r\nReferences: $orig\r\n"
    . "Date: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nForwarded again.\r\n");
check('passive forward, option off: no mail (osTicket default)', mails() === $mailsBefore && statusOf($tid) === $S['open'],
    "$mailsBefore -> " . mails());

// ------------------------------- catch-all: only passively matched mail

// Usual rule for the waiting statuses, then a catch-all for open and closed
// tickets that runs only for passively matched mail and reopens.
rules(array(
    'Customer replied' => array('act_alert_passive' => '1', 'act_note' => 'Rule "{rule}": {changes}') + $replyRule,
    'Passive mail' => array('trig_user' => '1', 'trig_collab' => '1', 'cond_passive' => '1', 'skip_auto' => '1',
        'cond_status' => sel(array($S['open'], $S['closed'])), 'act_status' => sel(array($S['open'])),
        'act_alert_passive' => '1', 'act_note' => 'Rule "{rule}": {changes}'),
));

/** Closes a ticket the way osTicket does it (status and closed timestamp). */
function closeTicket(int $tid): void
{
    global $S;
    q(sprintf('UPDATE %s SET status_id=%d, closed=NOW() WHERE ticket_id=%d', TICKET_TABLE, $S['closed'], $tid));
}

closeTicket($tid);
$notesBefore = count(autoNotes($tid));
$mailsBefore = mails();
pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Fwd: Press mailbox\r\n"
    . "Message-ID: <fwd3-" . bin2hex(random_bytes(6)) . "@example.com>\r\nReferences: $orig\r\n"
    . "Date: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nForwarded to a closed ticket.\r\n");
$notes = autoNotes($tid);
$last = lastMail();
check('closed + passive forward: reopened to open', statusOf($tid) === $S['open'] && col($tid, 'reopened') !== null,
    'status ' . statusOf($tid));
check('closed + passive forward: one alert mail to the agent', mails() === $mailsBefore + 1 && stripos($last['to'], $adminMail) !== false,
    "$mailsBefore -> " . mails() . ' ' . json_encode($last));
check('closed + passive forward: note from the catch-all rule', count($notes) === $notesBefore + 1
    && strpos(end($notes)['body'], 'Rule "Passive mail"') !== false && strpos(end($notes)['body'], 'alert sent to 1') !== false, json_encode(end($notes)));

// Ordinary reply on an open ticket: osTicket alerts, the catch-all stays out.
$notesBefore = count(autoNotes($tid));
$mailsBefore = mails();
pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Re: [#$number] Press mailbox\r\n"
    . "Message-ID: <re2-" . bin2hex(random_bytes(6)) . "@example.com>\r\nDate: " . date('r')
    . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAnother reply on an open ticket.\r\n");
check('open + ordinary reply: osTicket alert only, no note from the catch-all', mails() === $mailsBefore + 1
    && count(autoNotes($tid)) === $notesBefore, "$mailsBefore -> " . mails());

// Ordinary reply on a closed ticket: osTicket reopens and alerts itself.
closeTicket($tid);
$notesBefore = count(autoNotes($tid));
$mailsBefore = mails();
pipe("From: Alice Customer <alice@example.com>\r\nTo: helpdesk@example.com\r\nSubject: Re: [#$number] Press mailbox\r\n"
    . "Message-ID: <re3-" . bin2hex(random_bytes(6)) . "@example.com>\r\nDate: " . date('r')
    . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nReply to a closed ticket.\r\n");
check('closed + ordinary reply: osTicket reopens and alerts, plugin stays out', statusOf($tid) === $S['open']
    && mails() >= $mailsBefore + 1 && count(autoNotes($tid)) === $notesBefore, "$mailsBefore -> " . mails() . ' status ' . statusOf($tid));

// --------------------------------------------------- disabled instance

rules(array('Off' => $replyRule));
q(sprintf('UPDATE %s SET flags=0 WHERE plugin_id=%d', PLUGIN_INSTANCE_TABLE, $PID));
$t = ticket($S['wait_customer']);
step(array('action' => 'message', 'ticket' => $t['id'], 'user' => $ALICE));
check('disabled instance: no change', statusOf($t['id']) === $S['wait_customer'] && !autoNotes($t['id']));

rules(array());

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
