/* mailhub_calendar: the Calendar page on CalDAV, drawn with FullCalendar (assets/). */

var mailhubCal = {
    lib: null,          // promise of the FullCalendar global
    calendars: [],      // [{href, name, color}] from the last load
    current: null,      // the event open in the dialog

    loadLib: function(base) {
        if (window.FullCalendar) {
            return Promise.resolve(window.FullCalendar);
        }
        if (!this.lib) {
            this.lib = new Promise(function(resolve, reject) {
                var s = document.createElement('script');
                s.src = base + 'fullcalendar.min.js';
                s.onload = function() { resolve(window.FullCalendar); };
                s.onerror = function() { mailhubCal.lib = null; reject(new Error('FullCalendar did not load')); };
                document.head.appendChild(s);
            });
        }
        return this.lib;
    },

    api: function(action, fields, done) {
        var args = [
            {name: 'hm_ajax_hook', value: 'ajax_mailhub_calendar'},
            {name: 'mailhub_cal_action', value: action}
        ];
        Object.keys(fields).forEach(function(k) { args.push({name: k, value: fields[k]}); });
        Hm_Ajax.request(args, function(res) { done(res || {mailhub_cal_error: 'The server did not answer.'}); }, [], true, undefined, true);
    },

    showError: function(msg) {
        var box = $('.mailhub-cal-error');
        if (msg) { box.text(msg).removeClass('d-none'); } else { box.addClass('d-none').text(''); }
    },

    /* Dates: the page and the server exchange ISO 8601 with offset, or YYYY-MM-DD for all-day. */
    pad: function(n) { return (n < 10 ? '0' : '') + n; },
    isoDate: function(d) { return d.getFullYear() + '-' + this.pad(d.getMonth() + 1) + '-' + this.pad(d.getDate()); },
    isoLocal: function(d) { return this.isoDate(d) + 'T' + this.pad(d.getHours()) + ':' + this.pad(d.getMinutes()); },
    isoOffset: function(d) {
        var off = -d.getTimezoneOffset(), sign = off >= 0 ? '+' : '-';
        off = Math.abs(off);
        return this.isoLocal(d) + ':' + this.pad(d.getSeconds()) + sign + this.pad(Math.floor(off / 60)) + ':' + this.pad(off % 60);
    },
    addDays: function(d, n) { var r = new Date(d.getTime()); r.setDate(r.getDate() + n); return r; },

    toFcEvent: function(e) {
        var classes = [];
        if (e.partstat === 'TENTATIVE' || e.partstat === 'NEEDS-ACTION') { classes.push('mailhub-cal-tentative'); }
        if (e.status === 'CANCELLED' || e.partstat === 'DECLINED') { classes.push('mailhub-cal-cancelled'); }
        return {
            id: e.id, title: e.title || '(no title)', start: e.start, end: e.end, allDay: e.allDay,
            backgroundColor: e.color, borderColor: e.color, classNames: classes,
            editable: !e.readonly, extendedProps: e
        };
    },

    /* ---------- the dialog ---------- */

    dialog: function() { return bootstrap.Modal.getOrCreateInstance(document.getElementById('mailhub-cal-dialog')); },

    setTimeInputs: function(start, end, allDay) {
        var type = allDay ? 'date' : 'datetime-local';
        $('#mailhub-cal-start').attr('type', type).val(allDay ? this.isoDate(start) : this.isoLocal(start));
        // All-day ends are exclusive in iCalendar; show the last day instead.
        $('#mailhub-cal-end').attr('type', type).val(allDay ? this.isoDate(this.addDays(end, -1)) : this.isoLocal(end));
    },

    readTimeInputs: function(allDay) {
        var s = $('#mailhub-cal-start').val(), e = $('#mailhub-cal-end').val() || s;
        if (!s) { return null; }
        if (allDay) {
            var end = new Date(e + 'T00:00:00');
            return {start: s.substring(0, 10), end: this.isoDate(this.addDays(end, 1))};
        }
        return {start: this.isoOffset(new Date(s)), end: this.isoOffset(new Date(e))};
    },

    open: function(ev, start, end, allDay) {
        var form = $('.mailhub-cal-form')[0];
        form.reset();
        this.current = ev;
        var sel = $('#mailhub-cal-calendar').empty();
        this.calendars.forEach(function(c) { sel.append($('<option>').val(c.href).text(c.name)); });
        var readonly = ev && ev.readonly;
        $('#mailhub-cal-dialog-title').text(ev ? (readonly ? 'Meeting' : 'Edit event') : 'New event');
        $('#mailhub-cal-title').val(ev ? ev.title : '');
        $('#mailhub-cal-location').val(ev ? ev.location : '');
        $('#mailhub-cal-description').val(ev ? ev.description : '');
        if (ev) { sel.val(ev.calendar); }
        sel.prop('disabled', !!ev);   // moving between calendars: not supported
        $('#mailhub-cal-allday').prop('checked', allDay).prop('disabled', !!(ev && ev.recurring));
        this.setTimeInputs(start, end, allDay);
        $('.mailhub-cal-form').find('input,textarea').not('[type=checkbox]').prop('readonly', readonly);
        $('#mailhub-cal-allday').prop('disabled', readonly || !!(ev && ev.recurring));
        $('.mailhub-cal-save').toggleClass('d-none', !!readonly);
        $('.mailhub-cal-delete').toggleClass('d-none', !ev)
            .text(readonly ? 'Remove from calendar' : (ev && ev.recurring ? 'Delete all' : 'Delete'));
        $('.mailhub-cal-delete-one').toggleClass('d-none', !(ev && ev.recurring));
        var note = [];
        if (readonly) { note.push('Organized by ' + ev.organizer + '. Answer from the invitation email; only the organizer can change it.'); }
        if (ev && ev.recurring && !readonly) { note.push('Repeating event: changes apply to every occurrence.'); }
        $('.mailhub-cal-note').text(note.join(' ')).toggleClass('d-none', note.length === 0);
        this.dialog().show();
    },

    save: function(cal) {
        var ev = this.current, allDay = $('#mailhub-cal-allday').prop('checked');
        var times = this.readTimeInputs(allDay);
        var title = $('#mailhub-cal-title').val().trim();
        if (!title || !times) { $('.mailhub-cal-form')[0].reportValidity(); return; }
        var data = {
            title: title, allDay: allDay, start: times.start, end: times.end,
            location: $('#mailhub-cal-location').val(), description: $('#mailhub-cal-description').val(),
            calendar: $('#mailhub-cal-calendar').val()
        };
        if (ev) {
            data.href = ev.href; data.etag = ev.etag; data.instanceStart = ev.start;
        }
        this.send('save', data, cal, true);
    },

    send: function(action, data, cal, closeDialog, revert) {
        $('.mailhub-cal-save, .mailhub-cal-delete, .mailhub-cal-delete-one').prop('disabled', true);
        this.api(action, {mailhub_cal_event: JSON.stringify(data)}, function(res) {
            $('.mailhub-cal-save, .mailhub-cal-delete, .mailhub-cal-delete-one').prop('disabled', false);
            if (res.mailhub_cal_error) {
                mailhubCal.showError(res.mailhub_cal_error);
                if (revert) { revert(); }
            } else {
                mailhubCal.showError('');
            }
            if (closeDialog) { mailhubCal.dialog().hide(); }
            cal.refetchEvents();
        });
    },

    /* ---------- the page ---------- */

    mount: function() {
        var el = document.getElementById('mailhub-cal');
        if (!el) { return; }
        var calendar = null, destroyed = false;
        this.loadLib(el.getAttribute('data-assets')).then(function(FC) {
            if (destroyed) { return; }
            var narrow = window.matchMedia('(max-width: 767px)').matches;
            // FullCalendar's links have no href; keep them away from Cypht's page-navigation click handler.
            el.addEventListener('click', function(e) {
                var a = e.target.closest('a');
                if (a && !a.getAttribute('href')) { e.stopPropagation(); }
            });
            calendar = new FC.Calendar(el, {
                initialView: narrow ? 'listWeek' : 'timeGridWeek',
                headerToolbar: narrow
                    ? {left: 'prev,next', center: 'title', right: 'today'}
                    : {left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'},
                footerToolbar: narrow ? {center: 'dayGridMonth,timeGridDay,listWeek'} : false,
                // Cypht's CSP blocks FullCalendar's data: icon font; plain arrows instead.
                buttonIcons: false,
                buttonText: {prev: '‹', next: '›', today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'Agenda'},
                height: narrow ? 'auto' : Math.max(560, window.innerHeight - 150), firstDay: 1, nowIndicator: true, navLinks: true, weekNumbers: !narrow,
                scrollTime: '07:30:00', slotDuration: '00:30:00',
                eventTimeFormat: {hour: '2-digit', minute: '2-digit', hour12: false},
                slotLabelFormat: {hour: '2-digit', minute: '2-digit', hour12: false},
                selectable: true, selectMirror: true, editable: true, dayMaxEvents: true,
                events: function(info, success, failure) {
                    mailhubCal.api('list', {
                        mailhub_cal_start: mailhubCal.isoOffset(info.start),
                        mailhub_cal_end: mailhubCal.isoOffset(info.end)
                    }, function(res) {
                        if (res.mailhub_cal_error) {
                            mailhubCal.showError(res.mailhub_cal_error);
                            failure(new Error(res.mailhub_cal_error));
                            return;
                        }
                        mailhubCal.calendars = JSON.parse(res.mailhub_cal_calendars || '[]');
                        success(JSON.parse(res.mailhub_cal_events || '[]').map(mailhubCal.toFcEvent));
                    });
                },
                select: function(info) {
                    calendar.unselect();
                    if (!mailhubCal.calendars.length) { return; }
                    mailhubCal.open(null, info.start, info.end, info.allDay);
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    var e = info.event;
                    mailhubCal.open(e.extendedProps, e.start, e.end || (e.allDay ? mailhubCal.addDays(e.start, 1) : e.start), e.allDay);
                },
                eventChange: function(info) {
                    // Dragged or resized: save the new times straight away.
                    var p = info.event.extendedProps, e = info.event;
                    if (p.recurring && !window.confirm('This is a repeating event. Move every occurrence?')) {
                        info.revert();
                        return;
                    }
                    var end = e.end || (e.allDay ? mailhubCal.addDays(e.start, 1) : new Date(e.start.getTime() + 3600000));
                    mailhubCal.send('save', {
                        href: p.href, etag: p.etag, instanceStart: p.start, title: p.title,
                        location: p.location, description: p.description, allDay: e.allDay,
                        start: e.allDay ? mailhubCal.isoDate(e.start) : mailhubCal.isoOffset(e.start),
                        end: e.allDay ? mailhubCal.isoDate(end) : mailhubCal.isoOffset(end)
                    }, calendar, false, info.revert);
                }
            });
            calendar.render();
            $('.mailhub-cal-form').off('.mailhubcal')
                .on('submit.mailhubcal', function(ev) { ev.preventDefault(); mailhubCal.save(calendar); })
                .on('change.mailhubcal', '#mailhub-cal-allday', function() {
                    var t = mailhubCal.readTimeInputs(!this.checked);
                    if (!t) { return; }
                    var s = new Date(t.start.length === 10 ? t.start + 'T09:00:00' : t.start);
                    var e = new Date(t.end.length === 10 ? t.end + 'T00:00:00' : t.end);
                    mailhubCal.setTimeInputs(s, this.checked ? mailhubCal.addDays(new Date(mailhubCal.isoDate(e) + 'T00:00:00'), 1)
                        : new Date(s.getTime() + 3600000), this.checked);
                })
                .on('click.mailhubcal', '.mailhub-cal-delete, .mailhub-cal-delete-one', function() {
                    var ev = mailhubCal.current, one = $(this).hasClass('mailhub-cal-delete-one');
                    if (!ev) { return; }
                    var what = one ? 'this occurrence' : (ev.recurring ? 'every occurrence of this event' : 'this event');
                    if (!window.confirm('Delete ' + what + ' from the calendar?')) { return; }
                    mailhubCal.send('delete', {href: ev.href, etag: ev.etag, scope: one ? 'one' : 'all', recurrenceId: ev.recurrenceId}, calendar, true);
                });
        }).catch(function(err) { mailhubCal.showError(err.message); });

        return function() {
            destroyed = true;
            if (calendar) { calendar.destroy(); }
            var dlg = document.getElementById('mailhub-cal-dialog');
            if (dlg && bootstrap.Modal.getInstance(dlg)) { bootstrap.Modal.getInstance(dlg).hide(); }
        };
    }
};

/* The route Cypht's navigation calls for page=calendar (upstream calendar module disabled). */
function applyCalendarPageHandlers() {
    return mailhubCal.mount();
}
