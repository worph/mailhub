<?php
/**
 * mailhub_calendar handlers and outputs. See setup.php for what the module does.
 */
if (!defined('DEBUG_MODE')) { die(); }

use Sabre\VObject;

require_once APP_PATH . 'modules/mailhub_common/caldav.php';

/* ---------- reading ---------- */

/** Colours for calendars that have none of their own. */
if (!hm_exists('mailhub_cal_palette')) {
function mailhub_cal_palette($i) {
    $p = array('#2f6fdb', '#d9822b', '#2e9e6a', '#b24bcc', '#d6455d', '#2a9bb5', '#8a6d3b');
    return $p[$i % count($p)];
}}

/** The calendars under the calendar home that hold events: [{href, name, color}]. */
if (!hm_exists('mailhub_cal_calendars')) {
function mailhub_cal_calendars() {
    $home = mailhub_caldav_home();
    if ($home === '') {
        return array();
    }
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:A="http://apple.com/ns/ical/">'
        . '<D:prop><D:resourcetype/><D:displayname/><A:calendar-color/><C:supported-calendar-component-set/></D:prop>'
        . '</D:propfind>';
    list($code, $body) = mailhub_caldav('PROPFIND', $home, $xml, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
    $xp = $code == 207 ? mailhub_dav_xpath($body) : null;
    if (!$xp) {
        return array();
    }
    $out = array();
    foreach ($xp->query('//D:response') as $resp) {
        $ok = '[contains(string(D:status), " 200 ")]';
        if (!$xp->query('D:propstat' . $ok . '/D:prop/D:resourcetype/C:calendar', $resp)->length) {
            continue;
        }
        $comps = $xp->query('D:propstat' . $ok . '/D:prop/C:supported-calendar-component-set/C:comp', $resp);
        if ($comps->length) {
            $events = false;
            foreach ($comps as $c) {
                $events = $events || strtoupper($c->getAttribute('name')) === 'VEVENT';
            }
            if (!$events) {
                continue;
            }
        }
        $href = $xp->evaluate('string(D:href)', $resp);
        $name = trim($xp->evaluate('string(D:propstat' . $ok . '/D:prop/D:displayname)', $resp));
        $color = trim($xp->evaluate('string(D:propstat' . $ok . '/D:prop/A:calendar-color)', $resp));
        if (preg_match('/^#[0-9a-fA-F]{6}/', $color)) {
            $color = substr($color, 0, 7);   // #RRGGBBAA -> #RRGGBB
        } else {
            $color = mailhub_cal_palette(count($out));
        }
        $out[] = array(
            'href' => $href,
            'name' => $name !== '' ? $name : basename(rtrim($href, '/')),
            'color' => $color,
        );
    }
    return $out;
}}

/** The calendar objects of one calendar with an occurrence in [start, end): [[href, etag, VCalendar]]. */
if (!hm_exists('mailhub_cal_objects')) {
function mailhub_cal_objects($cal_href, $start, $end) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:prop><D:getetag/><C:calendar-data/></D:prop>'
        . '<C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT">'
        . '<C:time-range start="' . $start->format('Ymd\THis\Z') . '" end="' . $end->format('Ymd\THis\Z') . '"/>'
        . '</C:comp-filter></C:comp-filter></C:filter></C:calendar-query>';
    list($code, $body) = mailhub_caldav('REPORT', $cal_href, $xml, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
    $xp = $code == 207 ? mailhub_dav_xpath($body) : null;
    if (!$xp) {
        return array();
    }
    $out = array();
    foreach ($xp->query('//D:response') as $resp) {
        $data = $xp->evaluate('string(.//C:calendar-data)', $resp);
        if (!$data) {
            continue;
        }
        try {
            $vcal = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING);
        } catch (Exception $e) {
            continue;
        }
        $out[] = array($xp->evaluate('string(D:href)', $resp), trim($xp->evaluate('string(.//D:getetag)', $resp)), $vcal);
    }
    return $out;
}}

/** The VEVENT describing the series (no RECURRENCE-ID), else the first one. */
if (!hm_exists('mailhub_cal_master')) {
function mailhub_cal_master($vcal) {
    $first = null;
    foreach ($vcal->select('VEVENT') as $ev) {
        $first = $first ?: $ev;
        if (!isset($ev->{'RECURRENCE-ID'})) {
            return $ev;
        }
    }
    return $first;
}}

if (!hm_exists('mailhub_cal_mailto')) {
function mailhub_cal_mailto($value) {
    return mb_strtolower(preg_replace('/^mailto:/i', '', trim((string) $value)));
}}

/** Start and end of an event instance: [DateTime start, DateTime end, all-day]. */
if (!hm_exists('mailhub_cal_times')) {
function mailhub_cal_times($ev, $zone) {
    $all_day = !$ev->DTSTART->hasTime();
    $start = DateTime::createFromInterface($ev->DTSTART->getDateTime($zone));
    if (isset($ev->DTEND)) {
        $end = DateTime::createFromInterface($ev->DTEND->getDateTime($zone));
    } elseif (isset($ev->DURATION)) {
        $end = (clone $start)->add($ev->DURATION->getDateInterval());
    } else {
        $end = (clone $start)->modify($all_day ? '+1 day' : '+0 seconds');
    }
    if (!$all_day) {
        $start->setTimezone($zone);
        $end->setTimezone($zone);
    }
    return array($start, $end, $all_day);
}}

/** One instance as the page wants it. */
if (!hm_exists('mailhub_cal_event_json')) {
function mailhub_cal_event_json($ev, $master, $href, $etag, $cal, $zone, $mine) {
    list($start, $end, $all_day) = mailhub_cal_times($ev, $zone);
    $recurring = isset($master->RRULE) || isset($master->RDATE) || isset($ev->{'RECURRENCE-ID'});
    $rid = isset($ev->{'RECURRENCE-ID'}) ? (string) $ev->{'RECURRENCE-ID'}->getValue() : '';
    $organizer = isset($master->ORGANIZER) ? mailhub_cal_mailto($master->ORGANIZER->getValue()) : '';
    $partstat = '';
    foreach ($ev->select('ATTENDEE') as $att) {
        if (in_array(mailhub_cal_mailto($att->getValue()), $mine, true)) {
            $partstat = strtoupper((string) $att['PARTSTAT']);
        }
    }
    return array(
        'id' => $href . '#' . $rid,
        'href' => $href,
        'etag' => $etag,
        'calendar' => $cal['href'],
        'title' => isset($ev->SUMMARY) ? (string) $ev->SUMMARY : '',
        'start' => $all_day ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
        'end' => $all_day ? $end->format('Y-m-d') : $end->format(DATE_ATOM),
        'allDay' => $all_day,
        'location' => isset($ev->LOCATION) ? (string) $ev->LOCATION : '',
        'description' => isset($ev->DESCRIPTION) ? (string) $ev->DESCRIPTION : '',
        'recurring' => $recurring,
        'recurrenceId' => $rid,
        // Someone else's meeting: its time and title are theirs to change.
        'organizer' => $organizer,
        'readonly' => $organizer !== '' && !in_array($organizer, $mine, true),
        'partstat' => $partstat,
        'status' => isset($ev->STATUS) ? strtoupper((string) $ev->STATUS) : '',
        'color' => $cal['color'],
    );
}}

/** The addresses that are "me": every IMAP login with an @ and every profile address. */
if (!hm_exists('mailhub_cal_my_addresses')) {
function mailhub_cal_my_addresses($hmod) {
    $mine = array();
    foreach (Hm_IMAP_List::dump() as $server) {
        if (!empty($server['user']) && mb_strpos($server['user'], '@') !== false) {
            $mine[] = mb_strtolower($server['user']);
        }
    }
    if (class_exists('Hm_Profiles')) {
        Hm_Profiles::init($hmod);
        foreach (Hm_Profiles::getAll() as $p) {
            if (!empty($p['address'])) {
                $mine[] = mb_strtolower($p['address']);
            }
        }
    }
    return array_values(array_unique($mine));
}}

/* ---------- writing ---------- */

/** Parse a time sent by the page: ISO 8601 with offset, or a date for all-day. */
if (!hm_exists('mailhub_cal_parse_time')) {
function mailhub_cal_parse_time($value, $zone) {
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return new DateTime($value . 'T00:00:00', $zone);
    }
    $dt = DateTime::createFromFormat(DATE_ATOM, $value) ?: DateTime::createFromFormat('Y-m-d\TH:i:s.vP', $value)
        ?: DateTime::createFromFormat('Y-m-d\TH:iP', $value);
    if (!$dt) {
        throw new InvalidArgumentException('Bad date: ' . $value);
    }
    return $dt->setTimezone($zone);
}}

/** Replace DTSTART / DTEND of an event. */
if (!hm_exists('mailhub_cal_set_times')) {
function mailhub_cal_set_times($ev, $start, $end, $all_day) {
    unset($ev->DTSTART, $ev->DTEND, $ev->DURATION);
    if ($all_day) {
        if ($end <= $start) {
            $end = (clone $start)->modify('+1 day');
        }
        $ev->add('DTSTART', $start->format('Ymd'), array('VALUE' => 'DATE'));
        $ev->add('DTEND', $end->format('Ymd'), array('VALUE' => 'DATE'));
    } else {
        if ($end <= $start) {
            $end = (clone $start)->modify('+1 hour');
        }
        $ev->add('DTSTART', $start);
        $ev->add('DTEND', $end);
    }
}}

if (!hm_exists('mailhub_cal_set_text')) {
function mailhub_cal_set_text($ev, $name, $value) {
    unset($ev->{$name});
    $value = trim((string) $value);
    if ($value !== '') {
        $ev->add($name, $value);
    }
}}

if (!hm_exists('mailhub_cal_touch')) {
function mailhub_cal_touch($ev) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    unset($ev->DTSTAMP, $ev->{'LAST-MODIFIED'});
    $ev->add('DTSTAMP', $now);
    $ev->add('LAST-MODIFIED', $now);
    $ev->SEQUENCE = isset($ev->SEQUENCE) ? ((int) (string) $ev->SEQUENCE) + 1 : 1;
}}

/** Shift EXDATEs of the series and RECURRENCE-IDs of its overrides by $shift seconds. */
if (!hm_exists('mailhub_cal_shift_exceptions')) {
function mailhub_cal_shift_exceptions($vcal, $master, $shift) {
    $move = function ($prop) use ($shift) {
        $is_date = !$prop->hasTime();
        $out = array();
        foreach ($prop->getDateTimes() as $dt) {
            $d = DateTime::createFromInterface($dt)->modify(sprintf('%+d seconds', $shift));
            $out[] = $is_date ? $d->format('Ymd') : $d->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }
        return array($out, $is_date);
    };
    $exdates = array();
    foreach ($master->select('EXDATE') as $prop) {
        $exdates[] = $move($prop);
    }
    unset($master->EXDATE);
    foreach ($exdates as list($values, $is_date)) {
        foreach ($values as $v) {
            $master->add('EXDATE', $v, $is_date ? array('VALUE' => 'DATE') : array());
        }
    }
    foreach ($vcal->select('VEVENT') as $ev) {
        if (isset($ev->{'RECURRENCE-ID'})) {
            list($values, $is_date) = $move($ev->{'RECURRENCE-ID'});
            unset($ev->{'RECURRENCE-ID'});
            $ev->add('RECURRENCE-ID', $values[0], $is_date ? array('VALUE' => 'DATE') : array());
        }
    }
}}

/** Is this href inside the calendar home? (Never write anywhere else.) */
if (!hm_exists('mailhub_cal_in_home')) {
function mailhub_cal_in_home($href) {
    $home = parse_url(mailhub_caldav_home(), PHP_URL_PATH);
    $path = parse_url($href, PHP_URL_PATH);
    return $home && $path && strpos($path, $home) === 0 && strpos($path, '..') === false;
}}

/** GET one calendar object: [VCalendar, etag] or an error string. */
if (!hm_exists('mailhub_cal_get')) {
function mailhub_cal_get($href) {
    list($code, $body, $headers) = mailhub_caldav('GET', $href);
    if ($code != 200) {
        return $code == 404 ? 'This event no longer exists.' : 'The calendar server answered ' . $code . '.';
    }
    try {
        return array(VObject\Reader::read($body, VObject\Reader::OPTION_FORGIVING), $headers['etag'] ?? '');
    } catch (Exception $e) {
        return 'This event could not be read.';
    }
}}

/** PUT a calendar object, guarded by its etag (or by its absence for a new one). */
if (!hm_exists('mailhub_cal_put')) {
function mailhub_cal_put($href, $vcal, $etag) {
    $headers = array('Content-Type: text/calendar; charset=utf-8', $etag === null ? 'If-None-Match: *' : 'If-Match: ' . $etag);
    list($code) = mailhub_caldav('PUT', $href, $vcal->serialize(), $headers);
    if ($code == 412) {
        return 'This event was changed somewhere else in the meantime. The calendar has been reloaded.';
    }
    return ($code >= 200 && $code < 300) ? true : 'The calendar server answered ' . $code . '.';
}}

/* ---------- the ajax page ---------- */

/**
 * mailhub_cal_action:
 *   list   (mailhub_cal_start, mailhub_cal_end)  -> calendars + event instances
 *   save   (mailhub_cal_event: JSON)              -> creates or updates
 *   delete (mailhub_cal_event: JSON {href, etag, scope: all|one, recurrenceId})
 */
class Hm_Handler_mailhub_calendar_api extends Hm_Handler_Module {
    public function process() {
        $action = $this->request->post['mailhub_cal_action'] ?? '';
        $zone = new DateTimeZone($this->user_config->get('timezone_setting', 'UTC') ?: 'UTC');
        try {
            if ($action === 'list') {
                $this->list_events($zone);
            } elseif ($action === 'save') {
                $this->finish($this->save(json_decode($this->request->post['mailhub_cal_event'] ?? '', true), $zone));
            } elseif ($action === 'delete') {
                $this->finish($this->delete(json_decode($this->request->post['mailhub_cal_event'] ?? '', true)));
            } else {
                $this->out('mailhub_cal_error', 'Unknown action.');
            }
        } catch (Exception $e) {
            $this->out('mailhub_cal_error', $e->getMessage());
        }
    }

    private function finish($result) {
        if ($result === true) {
            $this->out('mailhub_cal_saved', '1');
        } else {
            $this->out('mailhub_cal_error', (string) $result);
        }
    }

    private function list_events($zone) {
        $utc = new DateTimeZone('UTC');
        $start = mailhub_cal_parse_time($this->request->post['mailhub_cal_start'] ?? '', $zone)->setTimezone($utc);
        $end = mailhub_cal_parse_time($this->request->post['mailhub_cal_end'] ?? '', $zone)->setTimezone($utc);
        if ($end <= $start || $start->diff($end)->days > 400) {
            throw new InvalidArgumentException('Bad date range.');
        }
        $calendars = mailhub_cal_calendars();
        if (!$calendars) {
            $this->out('mailhub_cal_error', 'The calendar server is not reachable, or has no calendar.');
            return;
        }
        $mine = mailhub_cal_my_addresses($this);
        $events = array();
        foreach ($calendars as $cal) {
            foreach (mailhub_cal_objects($cal['href'], $start, $end) as list($href, $etag, $vcal)) {
                $master = mailhub_cal_master($vcal);
                if (!$master) {
                    continue;
                }
                $recurring = isset($master->RRULE) || isset($master->RDATE);
                try {
                    $instances = $recurring ? $vcal->expand($start, $end, $zone) : $vcal;
                } catch (Exception $e) {
                    $instances = $vcal;
                }
                foreach ($instances->select('VEVENT') as $ev) {
                    if (!isset($ev->DTSTART)) {
                        continue;
                    }
                    $events[] = mailhub_cal_event_json($ev, $master, $href, $etag, $cal, $zone, $mine);
                }
            }
        }
        $this->out('mailhub_cal_calendars', json_encode($calendars));
        $this->out('mailhub_cal_events', json_encode($events));
    }

    private function save($data, $zone) {
        if (!is_array($data)) {
            return 'Nothing to save.';
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return 'An event needs a title.';
        }
        $all_day = !empty($data['allDay']);
        $start = mailhub_cal_parse_time($data['start'] ?? '', $zone);
        $end = mailhub_cal_parse_time($data['end'] ?? ($data['start'] ?? ''), $zone);

        if (empty($data['href'])) {
            // New event, in the chosen calendar.
            $cal = (string) ($data['calendar'] ?? '');
            if (!mailhub_cal_in_home($cal)) {
                return 'Unknown calendar.';
            }
            $uid = VObject\UUIDUtil::getUUID();
            $vcal = new VObject\Component\VCalendar();
            $vcal->PRODID = '-//MailHub//Cypht calendar//EN';
            $ev = $vcal->add('VEVENT', array('UID' => $uid));
            mailhub_cal_set_text($ev, 'SUMMARY', $title);
            mailhub_cal_set_text($ev, 'LOCATION', $data['location'] ?? '');
            mailhub_cal_set_text($ev, 'DESCRIPTION', $data['description'] ?? '');
            mailhub_cal_set_times($ev, $start, $end, $all_day);
            mailhub_cal_touch($ev);
            return mailhub_cal_put(rtrim($cal, '/') . '/' . $uid . '.ics', $vcal, null);
        }

        // Existing event.
        $href = (string) $data['href'];
        if (!mailhub_cal_in_home($href)) {
            return 'Unknown event.';
        }
        $got = mailhub_cal_get($href);
        if (!is_array($got)) {
            return $got;
        }
        list($vcal) = $got;
        $master = mailhub_cal_master($vcal);
        if (!$master) {
            return 'This event could not be read.';
        }
        $organizer = isset($master->ORGANIZER) ? mailhub_cal_mailto($master->ORGANIZER->getValue()) : '';
        if ($organizer !== '' && !in_array($organizer, mailhub_cal_my_addresses($this), true)) {
            return 'This meeting belongs to its organizer; only they can change it.';
        }
        $recurring = isset($master->RRULE) || isset($master->RDATE);
        mailhub_cal_set_text($master, 'SUMMARY', $title);
        mailhub_cal_set_text($master, 'LOCATION', $data['location'] ?? '');
        mailhub_cal_set_text($master, 'DESCRIPTION', $data['description'] ?? '');
        if (!$recurring) {
            mailhub_cal_set_times($master, $start, $end, $all_day);
        } else {
            // A recurring series moves as a whole: shift it by how far this occurrence moved.
            list($m_start, $m_end, $m_all_day) = mailhub_cal_times($master, $zone);
            $orig = mailhub_cal_parse_time($data['instanceStart'] ?? '', $zone);
            $shift = $start->getTimestamp() - $orig->getTimestamp();
            $length = max(0, $end->getTimestamp() - $start->getTimestamp());
            $new_start = (clone $m_start)->modify(sprintf('%+d seconds', $shift));
            $new_end = (clone $new_start)->modify(sprintf('+%d seconds', $length));
            if ($shift !== 0 || $length !== $m_end->getTimestamp() - $m_start->getTimestamp()) {
                mailhub_cal_set_times($master, $new_start, $new_end, $m_all_day);
            }
            if ($shift !== 0) {
                // Exceptions are pinned to occurrence times: move them with the series.
                mailhub_cal_shift_exceptions($vcal, $master, $shift);
            }
        }
        mailhub_cal_touch($master);
        return mailhub_cal_put($href, $vcal, (string) ($data['etag'] ?? ''));
    }

    private function delete($data) {
        if (!is_array($data) || empty($data['href']) || !mailhub_cal_in_home($data['href'])) {
            return 'Unknown event.';
        }
        $href = (string) $data['href'];
        $etag = (string) ($data['etag'] ?? '');
        if (($data['scope'] ?? 'all') === 'one' && !empty($data['recurrenceId'])) {
            // One occurrence: an EXDATE on the series, and drop its own override if any.
            $got = mailhub_cal_get($href);
            if (!is_array($got)) {
                return $got;
            }
            list($vcal) = $got;
            $master = mailhub_cal_master($vcal);
            $rid = (string) $data['recurrenceId'];
            foreach ($vcal->select('VEVENT') as $ev) {
                if (isset($ev->{'RECURRENCE-ID'}) && (string) $ev->{'RECURRENCE-ID'}->getValue() === $rid) {
                    $vcal->remove($ev);
                }
            }
            if (preg_match('/^\d{8}$/', $rid)) {
                $master->add('EXDATE', $rid, array('VALUE' => 'DATE'));
            } else {
                $master->add('EXDATE', $rid);
            }
            mailhub_cal_touch($master);
            return mailhub_cal_put($href, $vcal, $etag);
        }
        list($code) = mailhub_caldav('DELETE', $href, null, $etag !== '' ? array('If-Match: ' . $etag) : array());
        if ($code == 412) {
            return 'This event was changed somewhere else in the meantime. The calendar has been reloaded.';
        }
        return ($code >= 200 && $code < 300) || $code == 404 ? true : 'The calendar server answered ' . $code . '.';
    }
}

/* ---------- outputs ---------- */

class Hm_Output_mailhub_calendar_page extends Hm_Output_Module {
    protected function output() {
        return '<div class="mailhub-calendar p-0">'
            . '<div class="content_title px-3"><span>' . $this->trans('Calendar') . '</span></div>'
            . '<div class="mailhub-cal-error alert alert-warning m-3 d-none" role="alert"></div>'
            . '<div id="mailhub-cal" class="mailhub-cal" data-assets="modules/mailhub_calendar/assets/"></div>'
            . $this->dialog()
            . '</div>';
    }

    private function dialog() {
        $t = function ($s) { return $this->html_safe($this->trans($s)); };
        return '<div class="modal fade" id="mailhub-cal-dialog" tabindex="-1" aria-labelledby="mailhub-cal-dialog-title" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-scrollable"><form class="modal-content mailhub-cal-form" novalidate>'
            . '<div class="modal-header"><h5 class="modal-title" id="mailhub-cal-dialog-title">' . $t('Event') . '</h5>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . $t('Close') . '"></button></div>'
            . '<div class="modal-body">'
            . '<div class="mailhub-cal-note small text-secondary mb-2 d-none"></div>'
            . '<div class="mb-2"><label class="form-label" for="mailhub-cal-title">' . $t('Title') . '</label>'
            . '<input class="form-control" id="mailhub-cal-title" name="title" required maxlength="500"></div>'
            . '<div class="mb-2"><label class="form-label" for="mailhub-cal-calendar">' . $t('Calendar') . '</label>'
            . '<select class="form-select" id="mailhub-cal-calendar" name="calendar"></select></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mailhub-cal-allday" name="allDay">'
            . '<label class="form-check-label" for="mailhub-cal-allday">' . $t('All day') . '</label></div>'
            . '<div class="row g-2 mb-2">'
            . '<div class="col-sm-6"><label class="form-label" for="mailhub-cal-start">' . $t('Starts') . '</label>'
            . '<input class="form-control" id="mailhub-cal-start" name="start" type="datetime-local" required></div>'
            . '<div class="col-sm-6"><label class="form-label" for="mailhub-cal-end">' . $t('Ends') . '</label>'
            . '<input class="form-control" id="mailhub-cal-end" name="end" type="datetime-local" required></div></div>'
            . '<div class="mb-2"><label class="form-label" for="mailhub-cal-location">' . $t('Location') . '</label>'
            . '<input class="form-control" id="mailhub-cal-location" name="location" maxlength="500"></div>'
            . '<div class="mb-2"><label class="form-label" for="mailhub-cal-description">' . $t('Notes') . '</label>'
            . '<textarea class="form-control" id="mailhub-cal-description" name="description" rows="3"></textarea></div>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-outline-danger me-auto mailhub-cal-delete">' . $t('Delete') . '</button>'
            . '<button type="button" class="btn btn-outline-danger me-auto mailhub-cal-delete-one d-none">' . $t('Delete this one') . '</button>'
            . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . $t('Cancel') . '</button>'
            . '<button type="submit" class="btn btn-primary mailhub-cal-save">' . $t('Save') . '</button>'
            . '</div></form></div></div>';
    }
}

class Hm_Output_mailhub_calendar_link extends Hm_Output_Module {
    protected function output() {
        $res = '<li class="menu_calendar"><a class="unread_link" href="' . $this->build_page_url('calendar') . '">';
        if (!$this->get('hide_folder_icons')) {
            $res .= '<i class="bi bi-calendar-week-fill menu-icon"></i>';
        }
        $res .= '<span class="nav-label">' . $this->trans('Calendar') . '</span></a></li>';
        if ($this->format == 'HTML5') {
            return $res;
        }
        $this->concat('formatted_folder_list', $res);
    }
}
