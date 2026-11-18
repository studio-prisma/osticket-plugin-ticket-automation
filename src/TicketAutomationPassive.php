<?php

declare(strict_types=1);

/**
 * Passively matched mail and the alert osTicket skips for it.
 *
 * osTicket matches an incoming mail to a ticket in three ways
 * (ThreadEntry::lookupByEmailHeaders(), include/class.thread.php, 1.18.4):
 *
 *   1. a referenced Message-ID that osTicket generated itself (a reply to a
 *      mail from the help desk) - positive
 *   2. a referenced Message-ID of any earlier entry, e.g. the customer's own
 *      first mail when they forward it - "passive threading, listen mode"
 *   3. the ticket number in the subject - positive
 *
 * For case 2 ThreadEntry::postEmail() passes autorespond=false, and
 * Ticket::postMessage() returns before the "New Message" alert to agents.
 * The message lands in the ticket, nobody hears of it.
 *
 * isPassive() repeats steps 1 and 2 for an entry that is already stored;
 * newMessageAlert() sends the alert the core skipped, with the core's
 * settings, template and recipients (Ticket::postMessage(), same file
 * version). Nothing here sends anything to the customer.
 */
final class TicketAutomationPassive
{
    /** Referenced IDs looked at per mail; a References header can be long. */
    public const MAX_MIDS = 50;

    /**
     * Whether osTicket matched this message passively. False for anything
     * that did not come in by mail.
     */
    public static function isPassive(ThreadEntry $entry): bool
    {
        if ($entry->getType() !== 'M' || !method_exists($entry, 'getEmailHeaderArray')) {
            return false;
        }
        $headers = $entry->getEmailHeaderArray();
        if (!is_array($headers) || !$headers) {
            return false;
        }
        $mids = TicketAutomationMatcher::referencedMids(
            self::header($headers, 'In-Reply-To'),
            self::header($headers, 'References'),
            (string) $entry->getBody(),
            (string) $entry->getEmailMessageId()
        );
        $mids = array_slice($mids, 0, self::MAX_MIDS);
        if (!$mids) {
            return false;
        }
        foreach ($mids as $mid) {
            if (self::isOwnReference($mid)) {
                return false;
            }
        }
        return self::knownElsewhere($mids, (int) $entry->getId());
    }

    /**
     * osTicket's "New Message" alert for $entry, as Ticket::postMessage()
     * would have sent it.
     *
     * @return int alerts sent
     */
    public static function newMessageAlert(Ticket $ticket, ThreadEntry $entry): int
    {
        global $cfg;

        $dept = $ticket->getDept();
        if (!$cfg || !$cfg->alertONNewMessage() || !$dept
                || !($email = $dept->getAlertEmail())
                || !($tpl = $dept->getTemplate())
                || !($msg = $tpl->getNewMessageAlertMsgTemplate())) {
            return 0;
        }
        $msg = $ticket->replaceVars($msg->asArray(), array(
            'message' => $entry,
            'poster'  => (string) $entry->getName() !== '' ? (string) $entry->getName() : $ticket->getName(),
        ));

        $sent = array();
        foreach (self::recipients($ticket, $dept) as $staff) {
            if (!$staff || !method_exists($staff, 'getEmail') || !$staff->getEmail()
                    || !$staff->isAvailable() || in_array($staff->getEmail(), $sent, true)) {
                continue;
            }
            $alert = $ticket->replaceVars($msg, array('recipient' => $staff));
            $email->sendAlert($staff, $alert['subj'], $alert['body'], null, array('thread' => $entry));
            $sent[] = $staff->getEmail();
        }
        return count($sent);
    }

    // ------------------------------------------------------------ internals

    /** Recipients as Ticket::postMessage() builds them, duplicates allowed. */
    private static function recipients(Ticket $ticket, $dept): array
    {
        global $cfg;

        $list = array();
        if ($cfg->alertLastRespondentONNewMessage() && ($lr = $ticket->getLastRespondent())) {
            $list[] = $lr;
        }
        if ($cfg->alertAssignedONNewMessage() && $ticket->isAssigned()) {
            if ($staff = $ticket->getStaff()) {
                $list[] = $staff;
            } elseif ($team = $ticket->getTeam()) {
                $list = array_merge($list, self::members($team));
            }
        }
        if ($cfg->alertDeptManagerONNewMessage() && ($manager = $dept->getManager())) {
            $list[] = $manager;
        }
        if ($cfg->alertAcctManagerONNewMessage()
                && ($owner = $ticket->getOwner())
                && ($org = $owner->getOrganization())
                && ($acct = $org->getAccountManager())) {
            if ($acct instanceof Team) {
                $list = array_merge($list, self::members($acct));
            } else {
                $list[] = $acct;
            }
        }
        return $list;
    }

    private static function members($team): array
    {
        $m = $team->getMembersForAlerts();
        if (!$m) {
            return array();
        }
        return is_array($m) ? $m : iterator_to_array($m);
    }

    /** Step 1: a Message-ID this help desk generated, for an entry of that thread. */
    private static function isOwnReference(string $mid): bool
    {
        if (!class_exists('osTicket\\Mail\\Mailer')) {
            return false;
        }
        $info = \osTicket\Mail\Mailer::decodeMessageId($mid);
        if (!is_array($info) || empty($info['loopback']) || !isset($info['uid']) || empty($info['entryId'])) {
            return false;
        }
        $res = db_query(sprintf('SELECT thread_id FROM %s WHERE id=%d', THREAD_ENTRY_TABLE, (int) $info['entryId']));
        $row = $res ? db_fetch_row($res) : null;
        return $row && (int) $row[0] === (int) ($info['threadId'] ?? 0);
    }

    /** Step 2: one of the IDs belongs to another stored entry. */
    private static function knownElsewhere(array $mids, int $entryId): bool
    {
        $table = defined('THREAD_ENTRY_EMAIL_TABLE') ? THREAD_ENTRY_EMAIL_TABLE : TABLE_PREFIX . 'thread_entry_email';
        $quoted = array();
        foreach ($mids as $mid) {
            $quoted[] = db_input('<' . $mid . '>');
        }
        $res = db_query(sprintf('SELECT thread_entry_id FROM %s WHERE mid IN (%s) AND thread_entry_id<>%d LIMIT 1',
            $table, implode(',', $quoted), $entryId));
        return (bool) ($res ? db_fetch_row($res) : null);
    }

    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return is_array($v) ? implode(' ', array_map('strval', $v)) : (string) $v;
            }
        }
        return '';
    }
}
