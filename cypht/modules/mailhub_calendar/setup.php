<?php
/**
 * mailhub_calendar: the Calendar page, on the CalDAV server (Baïkal).
 *
 * Replaces Cypht's own calendar module (leave `calendar` out of CYPHT_MODULES):
 * same page name and menu entry, but the events live on the CalDAV server, so
 * phones and other clients see the same calendar. Month, week, day and agenda
 * views (FullCalendar), every calendar of the account in its colour, recurring
 * events, all-day events; create by selecting a time range, edit in a dialog or
 * by dragging. Accepted invitations (mailhub_invites) show up here.
 *
 * Configuration: see modules/mailhub_common/caldav.php.
 */
if (!defined('DEBUG_MODE')) { die(); }

handler_source('mailhub_calendar');
output_source('mailhub_calendar');

/* the page */
setup_base_page('calendar', 'core');
add_output('calendar', 'mailhub_calendar_page', true, 'mailhub_calendar', 'version_upgrade_checker', 'after');

/* the menu entry */
add_output('ajax_hm_folders', 'mailhub_calendar_link', true, 'mailhub_calendar', 'main_menu_content', 'before');

/* list / save / delete, all through one ajax page */
setup_base_ajax_page('ajax_mailhub_calendar', 'core');
add_handler('ajax_mailhub_calendar', 'load_imap_servers_from_config', true, 'imap', 'load_user_data', 'after');
add_handler('ajax_mailhub_calendar', 'mailhub_calendar_api', true, 'mailhub_calendar', 'load_imap_servers_from_config', 'after');

return array(
    'allowed_pages' => array(
        'calendar',
        'ajax_mailhub_calendar',
    ),
    'allowed_output' => array(
        'mailhub_cal_calendars' => array(FILTER_UNSAFE_RAW, false),   // JSON
        'mailhub_cal_events' => array(FILTER_UNSAFE_RAW, false),      // JSON
        'mailhub_cal_saved' => array(FILTER_UNSAFE_RAW, false),
        'mailhub_cal_error' => array(FILTER_UNSAFE_RAW, false),
    ),
    'allowed_get' => array(),
    'allowed_post' => array(
        'mailhub_cal_action' => FILTER_UNSAFE_RAW,
        'mailhub_cal_start' => FILTER_UNSAFE_RAW,
        'mailhub_cal_end' => FILTER_UNSAFE_RAW,
        'mailhub_cal_event' => FILTER_UNSAFE_RAW,
    ),
);
