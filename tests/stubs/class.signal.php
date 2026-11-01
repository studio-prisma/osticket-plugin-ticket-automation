<?php
// Records connections instead of dispatching.
class Signal
{
    public static $connected = array();
    static function connect($signal, $callable) { self::$connected[] = array($signal, $callable); }
}
