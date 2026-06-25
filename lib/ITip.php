<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/**
 * iMIP / iTIP handling (RFC 5546 / 6047): liest text/calendar-Einladungen aus
 * Mails, baut REPLY/COUNTER und verschickt sie als iMIP-Mail an den Organizer.
 */
class ITip
{
    public static function mailtoAddr(string $val): string
    {
        return strtolower(preg_replace('/^mailto:/i', '', trim($val)));
    }

    /** Parst eine iCalendar-Zeichenkette aus einem Mail-Part. Null bei Fehler/kein VEVENT. */
    public static function parse(string $ical): ?array
    {
        try {
            $vcal = Reader::read($ical, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$vcal || !isset($vcal->VEVENT)) {
            return null;
        }

        $ev     = $vcal->VEVENT;
        $method = isset($vcal->METHOD) ? strtoupper((string) $vcal->METHOD) : '';

        $org = null;
        if (isset($ev->ORGANIZER)) {
            $org = [
                'email' => self::mailtoAddr((string) $ev->ORGANIZER),
                'name'  => (string) ($ev->ORGANIZER['CN'] ?? ''),
            ];
        }

        $attendees = [];
        if (isset($ev->ATTENDEE)) {
            foreach ($ev->ATTENDEE as $att) {
                $attendees[] = [
                    'email'    => self::mailtoAddr((string) $att),
                    'name'     => (string) ($att['CN'] ?? ''),
                    'partstat' => strtoupper((string) ($att['PARTSTAT'] ?? 'NEEDS-ACTION')),
                ];
            }
        }

        $dtstart = null;
        $dtend   = null;
        $allday  = false;
        try {
            if (isset($ev->DTSTART)) {
                $dtstart = $ev->DTSTART->getDateTime();
                $allday  = !$ev->DTSTART->hasTime();
            }
            if (isset($ev->DTEND)) {
                $dtend = $ev->DTEND->getDateTime();
            }
        } catch (\Throwable $e) {
            // ungueltige Datumsangabe -> ohne Zeit weiter
        }

        return [
            'method'      => $method,
            'uid'         => (string) ($ev->UID ?? ''),
            'sequence'    => (int) (string) ($ev->SEQUENCE ?? 0),
            'summary'     => (string) ($ev->SUMMARY ?? ''),
            'location'    => (string) ($ev->LOCATION ?? ''),
            'description' => (string) ($ev->DESCRIPTION ?? ''),
            'dtstart'     => $dtstart,
            'dtend'       => $dtend,
            'allday'      => $allday,
            'organizer'   => $org,
            'attendees'   => $attendees,
        ];
    }

    /** Findet den ATTENDEE-Eintrag, der zu einer meiner Mailadressen passt. */
    public static function myAttendee(array $parsed, array $myEmails): ?array
    {
        $mine = array_map('strtolower', $myEmails);
        foreach ($parsed['attendees'] as $att) {
            if (in_array($att['email'], $mine, true)) {
                return $att;
            }
        }
        return null;
    }

    private static function attendeeSnapshot($att): array
    {
        $params = [];
        foreach ($att->parameters() as $p) {
            $params[strtoupper($p->name)] = (string) $p;
        }
        return ['value' => (string) $att, 'params' => $params];
    }

    /**
     * Baut eine METHOD:REPLY (RFC 5546): nur der antwortende ATTENDEE mit PARTSTAT,
     * sonst Original-Event (UID/ORGANIZER/DTSTART/SUMMARY bleiben).
     */
    public static function buildReply(string $originalIcal, string $myEmail, string $myName, string $partstat, ?string $comment = null): ?string
    {
        try {
            $vcal = Reader::read($originalIcal, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!isset($vcal->VEVENT)) {
            return null;
        }

        $ev   = $vcal->VEVENT;
        $mine = null;
        if (isset($ev->ATTENDEE)) {
            foreach ($ev->ATTENDEE as $att) {
                if (self::mailtoAddr((string) $att) === strtolower($myEmail)) {
                    $mine = self::attendeeSnapshot($att);
                    break;
                }
            }
        }
        if ($mine === null) {
            $mine = ['value' => 'mailto:' . $myEmail, 'params' => $myName ? ['CN' => $myName] : []];
        }
        $mine['params']['PARTSTAT'] = $partstat;

        $vcal->METHOD = 'REPLY';
        unset($ev->ATTENDEE);
        $ev->add('ATTENDEE', $mine['value'], $mine['params']);
        $ev->DTSTAMP = gmdate('Ymd\THis\Z');
        if (!isset($ev->SEQUENCE)) {
            $ev->SEQUENCE = 0;
        }
        // Alarme/Anhaenge gehoeren nicht in eine REPLY
        unset($ev->VALARM);
        if ($comment !== null && $comment !== '') {
            unset($ev->COMMENT);
            $ev->add('COMMENT', $comment);
        }

        return $vcal->serialize();
    }

    /** Baut eine METHOD:COUNTER mit neuem Start/Ende. */
    public static function buildCounter(string $originalIcal, \DateTimeInterface $start, \DateTimeInterface $end, ?string $comment): ?string
    {
        try {
            $vcal = Reader::read($originalIcal, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!isset($vcal->VEVENT)) {
            return null;
        }
        $ev = $vcal->VEVENT;
        $vcal->METHOD = 'COUNTER';
        unset($ev->DTSTART, $ev->DTEND);
        $ev->add('DTSTART', $start->format('Ymd\THis\Z'));
        $ev->add('DTEND', $end->format('Ymd\THis\Z'));
        $ev->DTSTAMP = gmdate('Ymd\THis\Z');
        if ($comment !== null && $comment !== '') {
            unset($ev->COMMENT);
            $ev->add('COMMENT', $comment);
        }
        return $vcal->serialize();
    }

    /** Bereitet das Event zum Speichern im eigenen Kalender vor (METHOD weg, mein PARTSTAT gesetzt). */
    public static function buildStoredEvent(string $originalIcal, string $myEmail, string $partstat): ?string
    {
        try {
            $vcal = Reader::read($originalIcal, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!isset($vcal->VEVENT)) {
            return null;
        }
        unset($vcal->METHOD);
        $ev = $vcal->VEVENT;
        if (isset($ev->ATTENDEE)) {
            foreach ($ev->ATTENDEE as $att) {
                if (self::mailtoAddr((string) $att) === strtolower($myEmail)) {
                    $att['PARTSTAT'] = $partstat;
                }
            }
        }
        return $vcal->serialize();
    }

    /** Verschickt eine iMIP-Mail (REPLY/COUNTER) an den Organizer ueber Roundcube-SMTP. */
    public static function send(\rcmail $rc, string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText, string $ics, string $method): bool
    {
        // Das Plugin buendelt (transitiv) eine eigene pear-Kopie und prependet sie per
        // composer include_paths.php in den include_path. Beim Laden von Mail_mime wuerde
        // dadurch ein ZWEITES PEAR.php geladen -> "Cannot redeclare _PEAR_call_destructors".
        // Darum die Plugin-pear-Pfade kurz entfernen, sodass Roundcubes (bereits geladenes)
        // PEAR/Mail_mime genutzt wird. Danach wiederherstellen.
        $origIncludePath = get_include_path();
        $clean = array_filter(
            explode(PATH_SEPARATOR, $origIncludePath),
            fn($d) => strpos($d, '/caldav_suite/vendor/pear/') === false
        );
        set_include_path(implode(PATH_SEPARATOR, $clean));

        try {
            return self::deliver($rc, $fromEmail, $fromName, $toEmail, $subject, $bodyText, $ics, $method);
        } finally {
            set_include_path($origIncludePath);
        }
    }

    private static function deliver(\rcmail $rc, string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText, string $ics, string $method): bool
    {
        $from = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        $message = new \Mail_mime("\r\n");
        $message->setParam('text_encoding', 'quoted-printable');
        $message->setParam('head_charset', RCUBE_CHARSET);
        $message->setParam('html_charset', RCUBE_CHARSET);
        $message->headers([
            'From'       => $from,
            'To'         => $toEmail,
            'Subject'    => $subject,
            'Date'       => $rc->user_date(),
            'Message-ID' => $rc->gen_message_id($fromEmail),
            'X-Sender'   => $fromEmail,
        ]);
        $message->setTXTBody($bodyText);
        // 7. Param (charset) traegt den method-Parameter in den Content-Type:
        //   Content-Type: text/calendar; charset=UTF-8; method=REPLY
        $message->addAttachment($ics, 'text/calendar', 'invite.ics', false, '8bit', '', RCUBE_CHARSET . '; method=' . $method);

        $smtp_error = null;
        $sent = $rc->deliver_message($message, $fromEmail, $toEmail, $smtp_error);

        return (bool) $sent;
    }
}
