<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/**
 * iMIP / iTIP handling (RFC 5546 / 6047): reads text/calendar invitations from
 * mails, builds REPLY/COUNTER and sends them as an iMIP mail to the organizer.
 */
class ITip
{
    public static function mailtoAddr(string $val): string
    {
        return strtolower(preg_replace('/^mailto:/i', '', trim($val)));
    }

    /** Parses an iCalendar string from a mail part. Null on error/no VEVENT. */
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
        $floating = false;
        try {
            if (isset($ev->DTSTART)) {
                $dtstart = $ev->DTSTART->getDateTime();
                $allday  = !$ev->DTSTART->hasTime();
                // Floating times (no Z, no TZID) have the UTC timezone in sabre/vobject
                // but must NOT be converted to the local timezone (otherwise 09:00 ->
                // 11:00 in summer time). Keep them as wall time.
                if (method_exists($ev->DTSTART, 'isFloating') && $ev->DTSTART->isFloating()) {
                    $floating = true;
                }
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
            'floating'    => $floating,
            'organizer'   => $org,
            'attendees'   => $attendees,
        ];
    }

    /** Finds the ATTENDEE entry that matches one of my mail addresses. */
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
     * Builds a METHOD:REPLY (RFC 5546): only the replying ATTENDEE with PARTSTAT,
     * otherwise the original event (UID/ORGANIZER/DTSTART/SUMMARY are kept).
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
        // Alarms/attachments do not belong in a REPLY
        unset($ev->VALARM);
        if ($comment !== null && $comment !== '') {
            unset($ev->COMMENT);
            $ev->add('COMMENT', $comment);
        }

        return $vcal->serialize();
    }

    /** Builds a METHOD:COUNTER with a new start/end. */
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

    /** Prepares the event for storing in one's own calendar (METHOD removed, my PARTSTAT set). */
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

    /** Sends an iMIP mail (REPLY/COUNTER) to the organizer via Roundcube SMTP. */
    public static function send(\rcmail $rc, string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText, string $ics, string $method): bool
    {
        // The plugin bundles (transitively) its own copy of PEAR and prepends it via
        // composer include_paths.php to the include_path. Loading Mail_mime would then
        // load a SECOND PEAR.php -> "Cannot redeclare _PEAR_call_destructors".
        // Therefore temporarily remove the plugin's PEAR paths so that Roundcube's
        // (already loaded) PEAR/Mail_mime is used. Restore afterwards.
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
        // The 7th param (charset) carries the method parameter into the Content-Type:
        //   Content-Type: text/calendar; charset=UTF-8; method=REPLY
        $message->addAttachment($ics, 'text/calendar', 'invite.ics', false, '8bit', '', RCUBE_CHARSET . '; method=' . $method);

        $smtp_error = null;
        $sent = $rc->deliver_message($message, $fromEmail, $toEmail, $smtp_error);

        return (bool) $sent;
    }
}
