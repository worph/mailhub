<?php
/**
 * mailhub_invites handlers and outputs. See setup.php for what the module does.
 */
if (!defined('DEBUG_MODE')) { die(); }

use Sabre\VObject;

/* ---------- helpers ---------- */

if (!hm_exists('mailhub_env')) {
function mailhub_env($key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = function_exists('env') ? env($key, $default) : $default;
    }
    return $v === null ? $default : $v;
}}

/** Part numbers of the calendar parts of a message (text/calendar, application/ics). */
if (!hm_exists('mailhub_calendar_parts')) {
function mailhub_calendar_parts($struct) {
    $imap = new Hm_IMAP();
    $imap->struct_object = new Hm_IMAP_Struct(array(), $imap);
    $found = array();
    foreach (array('calendar', 'ics') as $subtype) {
        $res = $imap->search_bodystructure($struct, array('subtype' => $subtype));
        if (is_array($res)) {
            foreach (array_keys($res) as $part) {
                $found[(string) $part] = true;
            }
        }
    }
    return array_keys($found);
}}

/** The addresses that are "me" for an IMAP account: its login and its profiles' addresses. */
if (!hm_exists('mailhub_my_addresses')) {
function mailhub_my_addresses($hmod, $imap_id) {
    $mine = array();
    $server = Hm_IMAP_List::dump($imap_id);
    if ($server && !empty($server['user']) && mb_strpos($server['user'], '@') !== false) {
        $mine[] = mb_strtolower($server['user']);
    }
    if (class_exists('Hm_Profiles')) {
        Hm_Profiles::init($hmod);
        foreach (Hm_Profiles::getAll() as $p) {
            if (($p['imap_id'] ?? '') === $imap_id && !empty($p['address'])) {
                $mine[] = mb_strtolower($p['address']);
            }
        }
    }
    return array_values(array_unique($mine));
}}

/** The SMTP server and From address to answer with, for the account that received the invitation. */
if (!hm_exists('mailhub_reply_route')) {
function mailhub_reply_route($hmod, $imap_id) {
    $server = Hm_IMAP_List::dump($imap_id);
    $from = $server['user'] ?? '';
    $name = '';
    $smtp_id = false;
    if (class_exists('Hm_Profiles')) {
        Hm_Profiles::init($hmod);
        foreach (Hm_Profiles::getAll() as $p) {
            if (($p['imap_id'] ?? '') === $imap_id && !empty($p['smtp_id'])) {
                $smtp_id = $p['smtp_id'];
                $from = $p['address'] ?: $from;
                $name = $p['name'] ?? '';
                if (!empty($p['default'])) {
                    break;
                }
            }
        }
    }
    if ($smtp_id === false) {
        // No profile: an SMTP server logging in as the same user.
        foreach (Hm_SMTP_List::dump() as $id => $smtp) {
            if (mb_strtolower($smtp['user'] ?? '') === mb_strtolower($from)) {
                $smtp_id = $id;
                break;
            }
        }
    }
    return array($smtp_id, $from, $name);
}}

if (!hm_exists('mailhub_mailto')) {
function mailhub_mailto($value) {
    return mb_strtolower(preg_replace('/^mailto:/i', '', trim((string) $value)));
}}

/** The VEVENT that describes the series (no RECURRENCE-ID), else the first one. */
if (!hm_exists('mailhub_master_event')) {
function mailhub_master_event($cal) {
    $first = null;
    foreach ($cal->select('VEVENT') as $ev) {
        $first = $first ?: $ev;
        if (!isset($ev->{'RECURRENCE-ID'})) {
            return $ev;
        }
    }
    return $first;
}}

/** My ATTENDEE property in an event, or null. */
if (!hm_exists('mailhub_my_attendee')) {
function mailhub_my_attendee($ev, $mine) {
    foreach ($ev->select('ATTENDEE') as $att) {
        if (in_array(mailhub_mailto($att->getValue()), $mine, true)) {
            return $att;
        }
    }
    return null;
}}

/* ---------- CalDAV ---------- */

if (!hm_exists('mailhub_caldav')) {
function mailhub_caldav($method, $href, $body = null, $headers = array()) {
    $base = rtrim(mailhub_env('MAILHUB_CALDAV_URL'), '/') . '/';
    if ($base === '/') {
        return array(0, 'MAILHUB_CALDAV_URL is not set');
    }
    $parts = parse_url($base);
    $url = preg_match('#^https?://#', $href) ? $href
        : ($href !== '' && $href[0] === '/' ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $href : $base . $href);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        CURLOPT_USERPWD => mailhub_env('MAILHUB_CALDAV_USER') . ':' . mailhub_env('MAILHUB_CALDAV_PASSWORD'),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array((int) $code, $res === false ? $err : $res);
}}

/** The calendar object holding this UID: [href, VCalendar] or [null, null]. */
if (!hm_exists('mailhub_caldav_find')) {
function mailhub_caldav_find($uid) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:prop><D:getetag/><C:calendar-data/></D:prop>'
        . '<C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT">'
        . '<C:prop-filter name="UID"><C:text-match collation="i;octet">' . htmlspecialchars($uid, ENT_XML1) . '</C:text-match></C:prop-filter>'
        . '</C:comp-filter></C:comp-filter></C:filter></C:calendar-query>';
    list($code, $body) = mailhub_caldav('REPORT', '', $xml, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
    if ($code != 207 || !$body) {
        return array(null, null);
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($body)) {
        return array(null, null);
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('D', 'DAV:');
    $xp->registerNamespace('C', 'urn:ietf:params:xml:ns:caldav');
    foreach ($xp->query('//D:response') as $resp) {
        $href = $xp->evaluate('string(D:href)', $resp);
        $data = $xp->evaluate('string(.//C:calendar-data)', $resp);
        if ($href && $data) {
            try {
                return array($href, VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING));
            } catch (Exception $e) {
                return array($href, null);
            }
        }
    }
    return array(null, null);
}}

/* ---------- the invitation ---------- */

/** Read the invitation from the message: [VCalendar, method] or [null, null]. */
if (!hm_exists('mailhub_invite_load')) {
function mailhub_invite_load($imap_id, $folder_hex, $uid, $part, $cache) {
    $mailbox = Hm_IMAP_List::get_connected_mailbox($imap_id, $cache);
    if (!$mailbox || !$mailbox->authed()) {
        return array(null, null);
    }
    $ics = $mailbox->get_message_content(hex2bin($folder_hex), $uid, $part);
    if (!$ics || stripos($ics, 'BEGIN:VCALENDAR') === false) {
        return array(null, null);
    }
    try {
        $cal = VObject\Reader::read($ics, VObject\Reader::OPTION_FORGIVING);
    } catch (Exception $e) {
        return array(null, null);
    }
    if (!mailhub_master_event($cal)) {
        return array(null, null);
    }
    $method = isset($cal->METHOD) ? strtoupper((string) $cal->METHOD) : 'PUBLISH';
    return array($cal, $method);
}}

/** What the card shows, as plain values. */
if (!hm_exists('mailhub_invite_summary')) {
function mailhub_invite_summary($cal, $method, $mine, $tz) {
    $ev = mailhub_master_event($cal);
    $zone = new DateTimeZone($tz ?: 'UTC');
    $start = isset($ev->DTSTART) ? $ev->DTSTART : null;
    $allday = $start && !$start->hasTime();
    $when = '';
    if ($start) {
        // getDateTime() only applies $zone to floating times; UTC and TZID times
        // keep their own zone, so convert them for display.
        $s = $start->getDateTime($zone)->setTimezone($zone);
        if (isset($ev->DTEND)) {
            $e = $ev->DTEND->getDateTime($zone)->setTimezone($zone);
        } elseif (isset($ev->DURATION)) {
            $e = $s->add(VObject\DateTimeParser::parseDuration((string) $ev->DURATION));
        } else {
            $e = $s;
        }
        if ($allday) {
            $last = $e > $s ? $e->modify('-1 day') : $s;
            $when = $s->format('D j M Y') . ($last->format('Ymd') !== $s->format('Ymd') ? ' – ' . $last->format('D j M Y') : '') . ' · all day';
        } else {
            $when = $s->format('D j M Y, H:i') . ' – ' . ($e->format('Ymd') === $s->format('Ymd') ? $e->format('H:i') : $e->format('D j M Y, H:i'))
                . ' (' . $zone->getName() . ')';
        }
    }
    $organizer = isset($ev->ORGANIZER) ? array(
        'email' => mailhub_mailto($ev->ORGANIZER->getValue()),
        'name' => isset($ev->ORGANIZER['CN']) ? (string) $ev->ORGANIZER['CN'] : '',
    ) : null;
    $attendees = array();
    foreach ($ev->select('ATTENDEE') as $att) {
        $attendees[] = array(
            'email' => mailhub_mailto($att->getValue()),
            'name' => isset($att['CN']) ? (string) $att['CN'] : '',
            'partstat' => isset($att['PARTSTAT']) ? strtoupper((string) $att['PARTSTAT']) : 'NEEDS-ACTION',
        );
    }
    $me = mailhub_my_attendee($ev, $mine);
    return array(
        'method' => $method,
        'uid' => (string) $ev->UID,
        'summary' => isset($ev->SUMMARY) ? (string) $ev->SUMMARY : '(no title)',
        'location' => isset($ev->LOCATION) ? (string) $ev->LOCATION : '',
        'when' => $when,
        'recurring' => isset($ev->RRULE),
        'organizer' => $organizer,
        'attendees' => $attendees,
        'me' => $me ? mailhub_mailto($me->getValue()) : ($mine[0] ?? ''),
        'invited' => (bool) $me,
        'partstat' => $me && isset($me['PARTSTAT']) ? strtoupper((string) $me['PARTSTAT']) : 'NEEDS-ACTION',
    );
}}

/** Set my answer on every VEVENT of a calendar (series and its exceptions). */
if (!hm_exists('mailhub_set_partstat')) {
function mailhub_set_partstat($cal, $mine, $me, $partstat) {
    foreach ($cal->select('VEVENT') as $ev) {
        $att = mailhub_my_attendee($ev, $mine);
        if (!$att) {
            $att = $ev->add('ATTENDEE', 'mailto:' . $me);
        }
        $att['PARTSTAT'] = $partstat;
        unset($att['RSVP']);
    }
}}

/* ---------- handlers ---------- */

/**
 * Message view: if the message carries an invitation, read it and pass it to the card.
 * @subpackage mailhub_invites/handler
 */
class Hm_Handler_mailhub_invite_check extends Hm_Handler_Module {
    public function process() {
        $struct = $this->get('msg_struct', array());
        if (!$struct) {
            return;
        }
        list($success, $form) = $this->process_form(array('imap_server_id', 'imap_msg_uid', 'folder'));
        if (!$success) {
            return;
        }
        $parts = mailhub_calendar_parts($struct);
        if (!$parts) {
            return;
        }
        $part = $parts[0];
        list($cal, $method) = mailhub_invite_load($form['imap_server_id'], $form['folder'], $form['imap_msg_uid'], $part, $this->cache);
        if (!$cal) {
            return;
        }
        $mine = mailhub_my_addresses($this, $form['imap_server_id']);
        $invite = mailhub_invite_summary($cal, $method, $mine, $this->user_config->get('timezone_setting', 'UTC'));
        $invite['part'] = $part;
        $invite['server'] = $form['imap_server_id'];
        $invite['uid_msg'] = $form['imap_msg_uid'];
        $invite['folder'] = $form['folder'];
        // What the calendar already says, so a second visit shows the answer given.
        list($href, $stored) = mailhub_caldav_find($invite['uid']);
        $invite['in_calendar'] = (bool) $href;
        if ($stored) {
            $ev = mailhub_master_event($stored);
            $att = $ev ? mailhub_my_attendee($ev, $mine) : null;
            if ($att && isset($att['PARTSTAT'])) {
                $invite['partstat'] = strtoupper((string) $att['PARTSTAT']);
            }
        }
        $this->out('mailhub_invite', $invite);
    }
}

/**
 * Accept / Maybe / Decline / Remove: update the calendar, then answer the organizer.
 * @subpackage mailhub_invites/handler
 */
class Hm_Handler_mailhub_invite_reply extends Hm_Handler_Module {
    public function process() {
        list($success, $form) = $this->process_form(array('imap_server_id', 'imap_msg_uid', 'folder', 'mailhub_invite_part', 'mailhub_invite_action'));
        if (!$success) {
            return;
        }
        $actions = array('accept' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'decline' => 'DECLINED', 'remove' => 'REMOVE');
        $action = $actions[$form['mailhub_invite_action']] ?? null;
        if (!$action) {
            return $this->fail('Unknown answer.');
        }
        list($cal, $method) = mailhub_invite_load($form['imap_server_id'], $form['folder'], $form['imap_msg_uid'], $form['mailhub_invite_part'], $this->cache);
        if (!$cal) {
            return $this->fail('The invitation could not be read from the message.');
        }
        $ev = mailhub_master_event($cal);
        $uid = (string) $ev->UID;
        list($href, $stored) = mailhub_caldav_find($uid);

        if ($action === 'REMOVE') {
            if ($href) {
                list($code, $body) = mailhub_caldav('DELETE', $href);
                if ($code >= 300 && $code != 404) {
                    return $this->fail("The calendar refused the change ($code).");
                }
            }
            Hm_Msgs::add('Removed from your calendar.', 'success');
            $this->out('mailhub_invite_status', 'REMOVED');
            return;
        }

        $mine = mailhub_my_addresses($this, $form['imap_server_id']);
        list($smtp_id, $from, $from_name) = mailhub_reply_route($this, $form['imap_server_id']);
        $me = mailhub_my_attendee($ev, $mine) ? mailhub_mailto(mailhub_my_attendee($ev, $mine)->getValue()) : ($mine[0] ?? mb_strtolower($from));

        // The calendar copy: the invitation as received, with my answer.
        $old = clone $cal;
        unset($old->METHOD);
        $new = clone $old;
        mailhub_set_partstat($new, $mine ?: array($me), $me, $action);

        if ($action === 'DECLINED') {
            if ($href) {
                mailhub_caldav('DELETE', $href);
            }
        } else {
            $target = $href ?: rawurlencode(preg_replace('/[^A-Za-z0-9@._-]/', '_', $uid)) . '.ics';
            list($code, $body) = mailhub_caldav('PUT', $target, $new->serialize(), array('Content-Type: text/calendar; charset=utf-8'));
            if ($code < 200 || $code >= 300) {
                return $this->fail("The calendar refused the event ($code).");
            }
        }

        // The answer to the organizer (iTIP REPLY), unless nobody asked for one.
        $sent = false;
        if ($method === 'REQUEST' && isset($ev->ORGANIZER)) {
            $broker = new VObject\ITip\Broker();
            $messages = $broker->parseEvent($new, 'mailto:' . $me, $old);
            $reply = null;
            foreach ($messages as $m) {
                if ($m->method === 'REPLY') {
                    $reply = $m;
                    break;
                }
            }
            if ($reply) {
                if ($smtp_id === false) {
                    return $this->fail('Saved in your calendar, but no outgoing server is set up for this account, so the organizer was not told.');
                }
                $err = $this->send_reply($smtp_id, $from, $from_name, mailhub_mailto($reply->recipient), $reply->message, $action, $ev);
                if ($err) {
                    return $this->fail('Saved in your calendar, but the answer could not be sent: ' . $err);
                }
                $sent = true;
            }
        }
        $label = array('ACCEPTED' => 'Accepted', 'TENTATIVE' => 'Answered maybe', 'DECLINED' => 'Declined')[$action];
        Hm_Msgs::add($label . ($sent ? '; the organizer was told.' : '.'), 'success');
        $this->out('mailhub_invite_status', $action);
    }

    private function fail($msg) {
        Hm_Msgs::add($msg, 'danger');
        $this->out('mailhub_invite_error', $msg);
    }

    /** Send the iTIP REPLY: a short text plus the text/calendar part (RFC 6047). */
    private function send_reply($smtp_id, $from, $from_name, $to, $vcal, $partstat, $ev) {
        $mailbox = Hm_SMTP_List::connect($smtp_id, false);
        if (!$mailbox || !$mailbox->authed()) {
            return 'could not log in to the outgoing server';
        }
        $verb = array('ACCEPTED' => 'Accepted', 'TENTATIVE' => 'Tentative', 'DECLINED' => 'Declined')[$partstat];
        $summary = isset($ev->SUMMARY) ? (string) $ev->SUMMARY : '';
        $who = $from_name ? $from_name : $from;
        $text = sprintf("%s has %s the invitation: %s\r\n", $who,
            array('ACCEPTED' => 'accepted', 'TENTATIVE' => 'tentatively accepted', 'DECLINED' => 'declined')[$partstat], $summary);
        $boundary = 'mh-' . bin2hex(random_bytes(12));
        $domain = mb_substr(mb_strrchr($from, '@') ?: '@localhost', 1);
        $enc = function ($s) { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; };
        $headers = array(
            'From' => ($from_name ? $enc($from_name) . ' ' : '') . '<' . $from . '>',
            'To' => '<' . $to . '>',
            'Subject' => $enc($verb . ': ' . $summary),
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        );
        $raw = '';
        foreach ($headers as $k => $v) {
            $raw .= "$k: $v\r\n";
        }
        $raw .= "\r\n--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\n"
            . "Content-Type: text/calendar; charset=UTF-8; method=REPLY\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($vcal->serialize()))
            . "--$boundary--\r\n";
        $err = $mailbox->send_message($from, array($to), $raw);
        return $err ? $err : null;
    }
}

/* ---------- output ---------- */

/**
 * The card above the message body.
 * @subpackage mailhub_invites/output
 */
class Hm_Output_mailhub_invite_card extends Hm_Output_Module {
    protected function output() {
        $inv = $this->get('mailhub_invite');
        if (!$inv) {
            return;
        }
        $h = function ($s) { return $this->html_safe($s); };
        $states = array('ACCEPTED' => 'You accepted', 'TENTATIVE' => 'You answered maybe', 'DECLINED' => 'You declined', 'NEEDS-ACTION' => '');
        $res = '<div class="mailhub-invite" data-server="' . $h($inv['server']) . '" data-uid="' . $h($inv['uid_msg']) . '" data-folder="' . $h($inv['folder']) . '" data-part="' . $h($inv['part']) . '" data-method="' . $h($inv['method']) . '">';
        $res .= '<div class="mailhub-invite-icon"><i class="bi bi-calendar-event"></i></div><div class="mailhub-invite-body">';
        $kind = $inv['method'] === 'CANCEL' ? 'Cancelled event' : ($inv['method'] === 'REQUEST' ? 'Invitation' : 'Event');
        $res .= '<div class="mailhub-invite-kind">' . $h($kind) . ($inv['recurring'] ? ' · repeats' : '') . '</div>';
        $res .= '<div class="mailhub-invite-title">' . $h($inv['summary']) . '</div>';
        $res .= '<div class="mailhub-invite-when">' . $h($inv['when']) . '</div>';
        if ($inv['location'] !== '') {
            $res .= '<div class="mailhub-invite-where"><i class="bi bi-geo-alt"></i> ' . $h($inv['location']) . '</div>';
        }
        if ($inv['organizer']) {
            $o = $inv['organizer'];
            $res .= '<div class="mailhub-invite-who">Organizer: ' . $h($o['name'] ? $o['name'] . ' <' . $o['email'] . '>' : $o['email']) . '</div>';
        }
        $others = array();
        foreach ($inv['attendees'] as $a) {
            $others[] = $a['name'] ?: $a['email'];
        }
        if ($others) {
            $res .= '<div class="mailhub-invite-who">Guests: ' . $h(implode(', ', array_slice($others, 0, 8))) . (count($others) > 8 ? ' +' . (count($others) - 8) : '') . '</div>';
        }
        $res .= '<div class="mailhub-invite-actions">';
        if ($inv['method'] === 'CANCEL') {
            $res .= $inv['in_calendar']
                ? '<button type="button" class="btn btn-sm btn-outline-danger" data-action="remove">Remove from calendar</button>'
                : '<span class="mailhub-invite-state">Not in your calendar.</span>';
        } else {
            $current = $inv['partstat'];
            foreach (array('accept' => array('ACCEPTED', 'Accept', 'btn-outline-success'), 'tentative' => array('TENTATIVE', 'Maybe', 'btn-outline-secondary'), 'decline' => array('DECLINED', 'Decline', 'btn-outline-danger')) as $act => $b) {
                $res .= '<button type="button" class="btn btn-sm ' . $b[2] . ($current === $b[0] ? ' active' : '') . '" data-action="' . $act . '" aria-pressed="' . ($current === $b[0] ? 'true' : 'false') . '">' . $h($b[1]) . '</button>';
            }
            $state = $states[$current] ?? '';
            if (!$state && $inv['in_calendar']) {
                $state = 'In your calendar';
            }
            $res .= '<span class="mailhub-invite-state">' . $h($state) . '</span>';
            if ($inv['method'] !== 'REQUEST') {
                $res .= '<span class="mailhub-invite-note">No answer is sent for this kind of event; it is only added to your calendar.</span>';
            } elseif (!$inv['invited']) {
                $res .= '<span class="mailhub-invite-note">You are not on the guest list as ' . $h($inv['me']) . '; answering adds you.</span>';
            }
        }
        $res .= '</div></div></div>';
        $this->out('msg_headers', $this->get('msg_headers', '') . $res, false);
    }
}
