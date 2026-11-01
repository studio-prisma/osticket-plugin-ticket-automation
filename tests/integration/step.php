<?php
/**
 * One action against the test instance, in a process of its own, so every
 * action bootstraps osTicket and the plugin's rules from the database the way
 * a real request does. Called by run.php:
 *
 *   php /tests/integration/step.php '{"action":"message", ...}'
 *
 * Prints one JSON object. Never run against a real installation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
$_SERVER['SCRIPT_NAME'] = '/step.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require 'main.inc.php';

$in = json_decode($argv[1] ?? '', true);
if (!is_array($in) || !isset($in['action'])) {
    fwrite(STDERR, "step: bad input\n");
    exit(2);
}

function out(array $data): void
{
    echo json_encode($data) . "\n";
    exit(0);
}

function ticketOf(array $in): Ticket
{
    $t = Ticket::lookup((int) $in['ticket']);
    if (!$t) {
        fwrite(STDERR, "step: no ticket {$in['ticket']}\n");
        exit(3);
    }
    return $t;
}

switch ($in['action']) {

    case 'create':
        $errors = array();
        $t = Ticket::create(array(
            'email'   => $in['email'],
            'name'    => $in['name'] ?? 'Customer',
            'subject' => $in['subject'] ?? 'Integration ticket',
            'message' => 'Opening message.',
            'deptId'  => (int) $in['dept'],
            'ip'      => '127.0.0.1',
            'source'  => 'API',
        ), $errors, 'api', false, false);
        if (!$t) {
            fwrite(STDERR, 'step: create failed ' . json_encode($errors) . "\n");
            exit(3);
        }
        $id = (int) $t->getId();
        // Status and due date set directly: the test needs the state, not
        // the path that leads to it. Answered, so "unanswered" is visible.
        $due = isset($in['due_hours']) ? sprintf('NOW() + INTERVAL %d HOUR', (int) $in['due_hours']) : 'NULL';
        db_query(sprintf('UPDATE %s SET status_id=%d, duedate=%s, isanswered=1, est_duedate=NULL WHERE ticket_id=%d',
            TICKET_TABLE, (int) $in['status'], $due, $id));
        out(array('id' => $id, 'number' => $t->getNumber()));

    case 'collab':
        $t = ticketOf($in);
        $errors = array();
        $c = $t->getThread()->addCollaborator(User::lookup((int) $in['user']), array('isactive' => 1), $errors);
        out(array('ok' => (bool) $c));

    case 'message':
        // Ticket::postMessage() is what the client portal and the mail
        // fetcher call. Mail headers make it a mail as far as the bounce and
        // auto-reply checks are concerned (saved with a Message-Id).
        $t = ticketOf($in);
        $vars = array(
            'userId'  => (int) $in['user'],
            'message' => $in['text'] ?? 'Customer reply.',
        );
        if (!empty($in['header'])) {
            $vars['header'] = $in['header'];
            $vars['mid'] = '<' . bin2hex(random_bytes(8)) . '@test.example>';
        }
        $m = $t->postMessage($vars, !empty($in['header']) ? 'Email' : 'Web');
        out(array('entry' => $m ? (int) $m->getId() : 0));

    case 'reply':
        $staff = Staff::lookup((int) $in['staff']);
        $GLOBALS['thisstaff'] = $staff;
        $t = ticketOf($in);
        $errors = array();
        $r = $t->postReply(array(
            'response' => 'Agent reply.',
            'staffId'  => $staff->getId(),
            'poster'   => $staff,
            'reply-to' => 'none',
        ), $errors, false, false);
        out(array('entry' => $r ? (int) $r->getId() : 0, 'errors' => $errors));

    case 'note':
        $staff = Staff::lookup((int) $in['staff']);
        $GLOBALS['thisstaff'] = $staff;
        $t = ticketOf($in);
        $errors = array();
        $n = $t->postNote(array('note' => 'Agent note.', 'title' => 'Note'), $errors, $staff, false);
        out(array('entry' => $n ? (int) $n->getId() : 0, 'errors' => $errors));

    case 'save':
        // The admin panel's save path: PluginInstance::update() with the
        // instance form, which runs TicketAutomationConfig::pre_save().
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $plugin = Plugin::lookup((int) $in['plugin']);
        $instance = $plugin ? $plugin->getInstance((int) $in['instance']) : null;
        if (!$instance) {
            fwrite(STDERR, "step: no instance\n");
            exit(3);
        }
        // Field widgets read the submitted values from $_POST (the form's
        // source), by plain field name among others, as the admin panel's
        // POST delivers them.
        $vars = array('name' => $instance->getName(), 'isactive' => 1, 'notes' => '') + $in['values'];
        $_POST = $vars;
        $errors = array();
        $ok = $instance->update($vars, $errors);
        $fieldErrors = array();
        foreach ($instance->getForm()->getFields() as $f) {
            if ($f->errors()) {
                $fieldErrors[$f->get('name')] = $f->errors();
            }
        }
        out(array('ok' => (bool) $ok, 'errors' => $errors, 'field_errors' => $fieldErrors));
}

fwrite(STDERR, "step: unknown action\n");
exit(2);
