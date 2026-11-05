<?php
/**
 * mailhub_invites: answer meeting invitations from the message view.
 *
 * An email carrying a text/calendar part (iTIP, RFC 5546) gets a card above its
 * body: what, when, where, who, and Accept / Maybe / Decline. A click writes the
 * event to the CalDAV calendar (Baïkal) with your answer and sends the iTIP REPLY
 * to the organizer through the SMTP server of the account that received it.
 * A cancellation gets "Remove from calendar".
 *
 * CalDAV target: MAILHUB_CALDAV_URL (the calendar collection), MAILHUB_CALDAV_USER,
 * MAILHUB_CALDAV_PASSWORD, from the environment.
 */
if (!defined('DEBUG_MODE')) { die(); }

handler_source('mailhub_invites');
output_source('mailhub_invites');

/* message view: detect the invitation and render the card */
add_handler('ajax_imap_message_content', 'mailhub_invite_check', true, 'mailhub_invites', 'imap_message_content', 'after');
add_output('ajax_imap_message_content', 'mailhub_invite_card', true, 'mailhub_invites', 'filter_message_headers', 'after');

/* the buttons */
setup_base_ajax_page('ajax_mailhub_invite_reply', 'core');
add_handler('ajax_mailhub_invite_reply', 'load_imap_servers_from_config', true, 'imap', 'load_user_data', 'after');
add_handler('ajax_mailhub_invite_reply', 'load_smtp_servers_from_config', true, 'smtp', 'load_imap_servers_from_config', 'after');
add_handler('ajax_mailhub_invite_reply', 'mailhub_invite_reply', true, 'mailhub_invites', 'load_smtp_servers_from_config', 'after');

return array(
    'allowed_pages' => array(
        'ajax_mailhub_invite_reply',
    ),
    'allowed_output' => array(
        'mailhub_invite_status' => array(FILTER_UNSAFE_RAW, false),
        'mailhub_invite_error' => array(FILTER_UNSAFE_RAW, false),
    ),
    'allowed_get' => array(),
    'allowed_post' => array(
        'mailhub_invite_part' => FILTER_UNSAFE_RAW,
        'mailhub_invite_action' => FILTER_UNSAFE_RAW,
    ),
);
