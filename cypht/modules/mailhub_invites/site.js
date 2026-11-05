'use strict';

/* mailhub_invites: the Accept / Maybe / Decline / Remove buttons on an invitation card.
   The card is rendered with the message, so listen at the document level. */
$(document).on('click', '.mailhub-invite [data-action]', function (e) {
    e.preventDefault();
    var button = $(this);
    var card = button.closest('.mailhub-invite');
    var buttons = card.find('[data-action]');
    var state = card.find('.mailhub-invite-state');
    buttons.prop('disabled', true);
    state.text('Sending…');
    Hm_Ajax.request(
        [
            {'name': 'hm_ajax_hook', 'value': 'ajax_mailhub_invite_reply'},
            {'name': 'imap_server_id', 'value': card.data('server')},
            {'name': 'imap_msg_uid', 'value': card.data('uid')},
            {'name': 'folder', 'value': card.data('folder')},
            {'name': 'mailhub_invite_part', 'value': card.data('part')},
            {'name': 'mailhub_invite_action', 'value': button.data('action')}
        ],
        function (res) {
            buttons.prop('disabled', false);
            if (res.mailhub_invite_error) {
                state.text(res.mailhub_invite_error);
                return;
            }
            var labels = {
                'ACCEPTED': 'You accepted',
                'TENTATIVE': 'You answered maybe',
                'DECLINED': 'You declined',
                'REMOVED': 'Removed from your calendar'
            };
            buttons.removeClass('active').attr('aria-pressed', 'false');
            if (res.mailhub_invite_status !== 'REMOVED') {
                button.addClass('active').attr('aria-pressed', 'true');
            } else {
                buttons.remove();
            }
            state.text(labels[res.mailhub_invite_status] || '');
        }
    );
});
