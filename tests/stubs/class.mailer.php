<?php

/**
 * Stand-in for osTicket\Mail\Mailer::decodeMessageId(), unit tests only.
 * Unknown IDs decode as foreign mail (loopback false), as in the core.
 */

namespace osTicket\Mail;

class Mailer
{
    /** @var array<string, array> Message-ID without brackets => decoded info */
    public static $decoded = array();

    public static function decodeMessageId($mid)
    {
        return self::$decoded[trim((string) $mid, '<>')] ?? array('loopback' => false, 'version' => false);
    }
}
