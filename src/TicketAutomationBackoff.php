<?php

declare(strict_types=1);

/**
 * Remembers due-date runs that changed nothing, so the next cron call does
 * not try the same ticket with the same rule again.
 *
 * A marker holds the ticket's fingerprint after the failed run and a time.
 * The ticket stays blocked for that rule while both hold: the fingerprint is
 * unchanged (nobody touched status, due dates or the thread) and the backoff
 * has not run out. Whatever comes first lifts the block: an agent changes the
 * ticket, or a day passes (a refusal can also end without the ticket changing,
 * an open task that gets closed, for example).
 *
 * Pure PHP: the runner reads and writes the markers and computes the
 * fingerprints. Markers of one rule: ticket ID => array{fp: string, until: int}.
 */
final class TicketAutomationBackoff
{
    public const SECONDS = 86400;
    public const MAX = 1000;

    /** Fingerprint of the ticket columns a change to the ticket touches. */
    public static function fingerprint(array $row): string
    {
        $parts = array();
        foreach (array('status_id', 'duedate', 'est_duedate', 'updated', 'lastupdate') as $col) {
            $parts[] = (string) ($row[$col] ?? '');
        }
        return md5(implode('|', $parts));
    }

    /**
     * Markers that still block: same fingerprint, time not run out. Tickets
     * missing from $fingerprints (deleted) are dropped.
     *
     * @param array<int, array{fp:string, until:int}> $markers
     * @param array<int, string> $fingerprints  ticket ID => current fingerprint
     * @return array<int, array{fp:string, until:int}>
     */
    public static function prune(array $markers, array $fingerprints, int $now): array
    {
        $out = array();
        foreach ($markers as $tid => $m) {
            $tid = (int) $tid;
            if (isset($fingerprints[$tid]) && is_array($m)
                && (string) ($m['fp'] ?? '') === $fingerprints[$tid] && (int) ($m['until'] ?? 0) > $now) {
                $out[$tid] = array('fp' => (string) $m['fp'], 'until' => (int) $m['until']);
            }
        }
        return $out;
    }

    /**
     * Adds or renews a marker. Beyond MAX markers the ones running out first
     * go; a ticket losing its marker early is only tried once more.
     *
     * @param array<int, array{fp:string, until:int}> $markers
     * @return array<int, array{fp:string, until:int}>
     */
    public static function mark(array $markers, int $ticketId, string $fp, int $now): array
    {
        $markers[$ticketId] = array('fp' => $fp, 'until' => $now + self::SECONDS);
        if (count($markers) > self::MAX) {
            uasort($markers, static function ($a, $b) {
                return $b['until'] <=> $a['until'];
            });
            $markers = array_slice($markers, 0, self::MAX, true);
        }
        return $markers;
    }

    /** @return array<int, array{fp:string, until:int}> */
    public static function decode(?string $json): array
    {
        $data = $json !== null && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return array();
        }
        $out = array();
        foreach ($data as $tid => $m) {
            if (is_array($m) && isset($m['fp'], $m['until']) && (int) $tid > 0) {
                $out[(int) $tid] = array('fp' => (string) $m['fp'], 'until' => (int) $m['until']);
            }
        }
        return $out;
    }

    public static function encode(array $markers): string
    {
        ksort($markers);
        return $markers ? (string) json_encode($markers, JSON_FORCE_OBJECT) : '';
    }
}
