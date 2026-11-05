<?php
/**
 * Shared by the MailHub Cypht modules (not a module itself: no setup.php).
 * CalDAV access to the calendar server (Baïkal), configured from the environment:
 *   MAILHUB_CALDAV_URL       the default calendar collection (where invitations go)
 *   MAILHUB_CALDAV_HOME      the calendar home holding every calendar
 *                            (default: the parent of MAILHUB_CALDAV_URL)
 *   MAILHUB_CALDAV_USER / MAILHUB_CALDAV_PASSWORD
 */
if (!defined('DEBUG_MODE')) { die(); }

if (!hm_exists('mailhub_env')) {
function mailhub_env($key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = function_exists('env') ? env($key, $default) : $default;
    }
    return $v === null ? $default : $v;
}}

/** The calendar home URL, with a trailing slash ('' when not configured). */
if (!hm_exists('mailhub_caldav_home')) {
function mailhub_caldav_home() {
    $home = mailhub_env('MAILHUB_CALDAV_HOME');
    if ($home === '') {
        $url = rtrim(mailhub_env('MAILHUB_CALDAV_URL'), '/');
        $home = $url === '' ? '' : substr($url, 0, strrpos($url, '/'));
    }
    return $home === '' ? '' : rtrim($home, '/') . '/';
}}

/**
 * One CalDAV request. $href is absolute (http...), server-absolute (/dav.php/...)
 * or relative to MAILHUB_CALDAV_URL. Returns [status, body, response headers].
 */
if (!hm_exists('mailhub_caldav')) {
function mailhub_caldav($method, $href, $body = null, $headers = array()) {
    $base = rtrim(mailhub_env('MAILHUB_CALDAV_URL'), '/') . '/';
    if ($base === '/') {
        return array(0, 'MAILHUB_CALDAV_URL is not set', array());
    }
    $parts = parse_url($base);
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $url = preg_match('#^https?://#', $href) ? $href
        : ($href !== '' && $href[0] === '/' ? $origin . $href : $base . $href);
    // Only ever talk to the configured server.
    if (strpos($url, $origin . '/') !== 0) {
        return array(0, 'refusing a URL outside the CalDAV server', array());
    }
    $resp_headers = array();
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        CURLOPT_USERPWD => mailhub_env('MAILHUB_CALDAV_USER') . ':' . mailhub_env('MAILHUB_CALDAV_PASSWORD'),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resp_headers) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $resp_headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array((int) $code, $res === false ? $err : $res, $resp_headers);
}}

/** Parse a multistatus body: an XPath over it with D: and C: bound, or null. */
if (!hm_exists('mailhub_dav_xpath')) {
function mailhub_dav_xpath($body) {
    $doc = new DOMDocument();
    if (!$body || !@$doc->loadXML($body)) {
        return null;
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('D', 'DAV:');
    $xp->registerNamespace('C', 'urn:ietf:params:xml:ns:caldav');
    $xp->registerNamespace('A', 'http://apple.com/ns/ical/');
    return $xp;
}}
