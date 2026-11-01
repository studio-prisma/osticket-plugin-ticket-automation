<?php
/**
 * Seeds the local test instance. Runs inside the osticket container:
 *
 *   php /seed/seed.php
 *
 * Refuses to run twice (the database lives on tmpfs; dev/down.sh followed by
 * dev/up.sh starts over).
 *
 * Data set:
 *   Statuses   Open (installer), Closed (installer), plus
 *              "Waiting for customer", "Waiting for vendor", "Resubmission"
 *              (all open, enabled) and "Parked" (open, disabled).
 *   Users      alice@example.com (ticket owner), bob@example.com (collaborator)
 *   Agents     the installer's admin, supportagent (Support)
 *   Plugin     installed and active, no instance: the integration suite
 *              creates its rules itself.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
$_SERVER['SCRIPT_NAME'] = '/seed.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require 'main.inc.php';

function fail(string $msg): void
{
    fwrite(STDERR, "seed: $msg\n");
    fwrite(STDERR, "seed: partial data is left behind, start over with dev/down.sh and dev/up.sh\n");
    exit(1);
}

function q(string $sql): void
{
    if (!db_query($sql)) {
        fail('query failed: ' . $sql . ': ' . db_error());
    }
}

function scalar(string $sql)
{
    $res = db_query($sql);
    $row = $res ? db_fetch_row($res) : null;
    return $row ? $row[0] : null;
}

$agentUser = getenv('SPTA_AGENT_USER') ?: fail('SPTA_AGENT_USER missing');
$agentPass = getenv('SPTA_AGENT_PASS') ?: fail('SPTA_AGENT_PASS missing');
$clientPass = getenv('SPTA_CLIENT_PASS') ?: fail('SPTA_CLIENT_PASS missing');

if (scalar("SELECT COUNT(*) FROM " . TICKET_STATUS_TABLE . " WHERE name='Resubmission'") > 0) {
    echo "seed: already seeded, nothing to do\n";
    exit(0);
}

foreach (Ticket::objects() as $t) {
    $t->delete();
}

$cfg->updateAll(array(
    'enable_captcha' => 0,
    'client_registration' => 'public',
    'collaborator_ticket_visibility' => 1,
    // Warnings reach the system log; the suite reads the plugin's entries there.
    'log_level' => 2,
));

function status(string $name, int $mode, int $sort): int
{
    q(sprintf("INSERT INTO %s (name, state, mode, flags, sort, properties, created, updated)
               VALUES (%s, 'open', %d, 0, %d, '{}', NOW(), NOW())",
        TICKET_STATUS_TABLE, db_input($name), $mode, $sort));
    return (int) db_insert_id();
}

$open = (int) scalar("SELECT id FROM " . TICKET_STATUS_TABLE . " WHERE name='Open'");
$closed = (int) scalar("SELECT id FROM " . TICKET_STATUS_TABLE . " WHERE name='Closed'");
$waitCustomer = status('Waiting for customer', 3, 2);
$waitVendor = status('Waiting for vendor', 3, 3);
$resubmission = status('Resubmission', 3, 4);
$parked = status('Parked', 0, 5);

function client(string $email, string $name, string $pass): int
{
    $user = User::fromVars(array('email' => $email, 'name' => $name));
    if (!$user) {
        fail("user $email");
    }
    $errors = array();
    if (!UserAccount::register($user, array(
        'passwd1' => $pass, 'passwd2' => $pass, 'timezone' => 'UTC', 'backend' => 'client',
    ), $errors)) {
        fail("account $email: " . json_encode($errors));
    }
    return (int) $user->getId();
}

$alice = client('alice@example.com', 'Alice Customer', $clientPass);
$bob = client('bob@example.com', 'Bob Colleague', $clientPass);

$support = (int) scalar("SELECT id FROM " . DEPT_TABLE . " WHERE name='Support'");
$adminId = (int) scalar("SELECT staff_id FROM " . STAFF_TABLE . " ORDER BY staff_id LIMIT 1");

$errors = array();
$agent = Staff::create(array(
    'username'  => $agentUser,
    'firstname' => 'Support',
    'lastname'  => 'Agent',
    'email'     => 'support.agent@example.com',
    'dept_id'   => $support,
    'role_id'   => 1,
    'isactive'  => 1,
    'isadmin'   => 0,
    'isvisible' => 1,
));
if (!$agent->save()) {
    fail('agent save');
}
$agent->setPassword($agentPass);
$agent->save();

$plugin = $ost->plugins->install('ticket-automation');
if (!$plugin) {
    fail('plugin install');
}
$pid = (int) $plugin->getId();
q(sprintf("UPDATE %s SET isactive=1 WHERE id=%d", PLUGIN_TABLE, $pid));

echo json_encode(array(
    'status' => array(
        'open' => $open, 'closed' => $closed, 'wait_customer' => $waitCustomer,
        'wait_vendor' => $waitVendor, 'resubmission' => $resubmission, 'parked' => $parked,
    ),
    'users'  => array('alice' => $alice, 'bob' => $bob),
    'staff'  => array('admin' => $adminId, 'agent' => (int) $agent->getId()),
    'dept'   => array('support' => $support),
    'plugin' => $pid,
), JSON_PRETTY_PRINT) . "\n";
