/**
 * Front Desk grid - interactions: drag & drop, booking actions, new booking.
 */
(function ($) {
    'use strict';

    var frontDesk = {
        windowDays: 14,
        dateFrom: null,
        gridUrl: null,
        cellSelection: null,

        init: function () {
            frontDesk.windowDays = parseInt($('#qlofd-date-from-hidden').data('window-days'), 10) || 14;
            frontDesk.dateFrom = $('#qlofd-date-from-hidden').val();
            frontDesk.gridUrl = new URL(window.location.href);

            frontDesk.bindFilters();
            frontDesk.initBarDrag();
            frontDesk.initCellCreateSelection();
            frontDesk.initBarContext();
            frontDesk.initNewBookingButton();
            frontDesk.initHousekeeping();
            frontDesk.initOooMode();
            frontDesk.initUndoButton();
            frontDesk.initShortcuts();
            frontDesk.initModals();
        },

        /* ---------------------------------------------------------- filters */

        bindFilters: function () {
            $('#qlofd-apply-filters').on('click', frontDesk.applyFilters);
            $('#qlofd-today').on('click', function () { frontDesk.setDateFromAndReload(null); });
            $('#qlofd-prev-window').on('click', function () { frontDesk.shiftWindow(-frontDesk.windowDays); });
            $('#qlofd-next-window').on('click', function () { frontDesk.shiftWindow(frontDesk.windowDays); });
            $('#qlofd-date-from').datepicker({
                dateFormat: 'dd-mm-yy',
                onSelect: function (d) { frontDesk.setDateFromAndReload(d); }
            });
            $('#qlofd-hotel').on('change', frontDesk.applyFilters);
            $('#qlofd-room-type').on('change', frontDesk.applyFilters);
        },

        applyFilters: function () {
            var url = frontDesk.gridUrl;
            url.searchParams.set('id_hotel', $('#qlofd-hotel').val() || '0');
            url.searchParams.set('id_room_type', $('#qlofd-room-type').val() || '0');
            window.location.href = url.toString();
        },

        shiftWindow: function (days) {
            var d = frontDesk.parseISO(frontDesk.dateFrom);
            d.setDate(d.getDate() + days);
            frontDesk.setDateFromAndReload(frontDesk.toDMY(d));
        },

        setDateFromAndReload: function (dateDMY) {
            var d = dateDMY ? frontDesk.parseDMY(dateDMY) : new Date();
            var url = frontDesk.gridUrl;
            url.searchParams.set('date_from', frontDesk.toISO(d));
            window.location.href = url.toString();
        },

        toDMY: function (d) {
            return ('0' + d.getDate()).slice(-2) + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + d.getFullYear();
        },

        parseDMY: function (s) {
            var p = s.split('-');
            return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
        },

        toISO: function (d) {
            return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        },

        parseISO: function (s) {
            var p = s.split('-');
            return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        },

        addDays: function (iso, days) {
            var d = frontDesk.parseISO(iso);
            d.setDate(d.getDate() + days);
            return frontDesk.toISO(d);
        },

        /* -------------------------------------------------------- grid utils */

        refreshGrid: function () {
            frontDesk.fetchGridData(function (data) {
                frontDesk.renderGridData(data);
            });
        },

        renderGridData: function (data) {
            frontDesk.dateFrom = data.date_from;

            data.rooms.forEach(function (room) {
                var $row = $('.qlofd-room-row[data-room-id="' + room.id + '"]');
                if (!$row.length) {
                    return;
                }
                var $barsLayer = $row.find('.qlofd-bars-layer').empty();
                var $oooLayer = $row.find('.qlofd-ooo-layer').empty();

                (data.ooo || []).filter(function (o) { return o.id_room === room.id; }).forEach(function (ooo) {
                    $('<div class="qlofd-ooo-bar" style="left:' + ooo.left + '%;width:' + ooo.width + '%" title="' + (ooo.reason || '') + '"><i class="icon-ban"></i></div>')
                        .attr({
                            'data-ooo-id': ooo.id,
                            'data-date-from': ooo.date_from,
                            'data-date-to': ooo.date_to
                        })
                        .appendTo($oooLayer);
                });

                (data.booking_bars || []).filter(function (b) { return b.id_room === room.id; }).forEach(function (bar) {
                    var $bar = $('<div class="qlofd-bar qlofd-bar-' + bar.status + '">' +
                        '<span class="qlofd-bar-name">' + $('<span>').text(bar.customer).html() + '</span>' +
                        '</div>')
                        .attr({
                            'data-booking-id': bar.id_htl_booking,
                            'data-room-id': bar.id_room,
                            'data-room-type': bar.id_product,
                            'data-date-from': bar.date_from,
                            'data-date-to': bar.date_to,
                            'data-status': bar.status
                        })
                        .css({ left: bar.left + '%', width: bar.width + '%' })
                        .appendTo($barsLayer);

                    if (bar.is_locked_assignment) {
                        $bar.addClass('qlofd-bar-locked').append('<i class="qlofd-lock icon-lock"></i>');
                    }
                    if (bar.is_no_show) {
                        $bar.addClass('qlofd-bar-noshow');
                    }
                    if (bar.group_overlap >= frontDesk.windowDaysThreshold()) {
                        $bar.append('<span class="qlofd-bar-group">' + bar.group_overlap + '</span>');
                    }
                    if (bar.no_show_label === 'high') {
                        $bar.append('<span class="qlofd-bar-risk">risk</span>');
                    }
                });
            });

            frontDesk.initBarDrag();
            frontDesk.initBarContext();
            frontDesk.initHousekeeping();
        },

        tr: function (key, fallback) {
            return (typeof qlofdTranslations !== 'undefined' && qlofdTranslations[key]) ? qlofdTranslations[key] : fallback;
        },

        windowDaysThreshold: function () {
            return (typeof qlofdGroupThreshold !== 'undefined') ? qlofdGroupThreshold : 2;
        },

        fetchGridData: function (callback) {
            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: {
                    ajax: 1,
                    action: 'getGridData',
                    id_hotel: $('#qlofd-hotel').val() || 0,
                    id_room_type: $('#qlofd-room-type').val() || 0,
                    date_from: frontDesk.dateFrom
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        callback(response.data);
                    }
                }
            });
        },

        /* ----------------------------------------------------------- bar drag */

        initBarDrag: function () {
            $('.qlofd-bar').draggable({
                revert: 'invalid',
                scroll: true,
                containment: '.qlofd-grid',
                helper: function () {
                    return $(this).clone().addClass('qlofd-row-dragging').css({
                        position: 'absolute',
                        width: $(this).width(),
                        zIndex: 30
                    });
                },
                start: function (event, ui) {
                    var $bar = $(this);
                    if ($bar.hasClass('qlofd-bar-locked')) {
                        return false;
                    }
                    frontDesk.dragSource = $bar;
                    frontDesk.dragStartDay = frontDesk.dayIndexAt($bar, event.pageX);
                    $bar.addClass('qlofd-drag-source');
                },
                stop: function () {
                    frontDesk.dragSource = null;
                }
            });

            $('.qlofd-room-row').droppable({
                accept: '.qlofd-bar',
                hoverClass: 'qlofd-drag-over',
                drop: function (event, ui) {
                    var $source = ui.draggable;
                    if (!frontDesk.dragSource) {
                        return;
                    }
                    var $targetRow = $(this);
                    var targetRoomId = parseInt($targetRow.attr('data-room-id'), 10);
                    var sourceRoomId = parseInt($source.attr('data-room-id'), 10);

                    var targetDay = frontDesk.dayIndexAt($targetRow, event.pageX);
                    var deltaDays = Math.round(targetDay - frontDesk.dragStartDay);
                    var dateFrom = $source.attr('data-date-from');
                    var dateTo = $source.attr('data-date-to');

                    var $targetBar = $(event.target).closest('.qlofd-bar');
                    if ($targetBar.length && $targetBar.attr('data-booking-id') !== $source.attr('data-booking-id')) {
                        frontDesk.ajaxAction('swapBooking', {
                            id_htl_booking_from: $source.attr('data-booking-id'),
                            id_htl_booking_to: $targetBar.attr('data-booking-id')
                        });
                        return;
                    }

                    if (targetRoomId !== sourceRoomId) {
                        frontDesk.ajaxAction('moveBookingRoom', {
                            id_htl_booking: $source.attr('data-booking-id'),
                            id_room: targetRoomId
                        });
                        return;
                    }

                    if (deltaDays !== 0) {
                        frontDesk.ajaxAction('updateBookingDates', {
                            id_htl_booking: $source.attr('data-booking-id'),
                            date_from: frontDesk.addDays(dateFrom, deltaDays),
                            date_to: frontDesk.addDays(dateTo, deltaDays)
                        });
                    }
                }
            });
        },

        dayIndexAt: function ($element, pageX) {
            var $timeline = $element.hasClass('qlofd-room-row')
                ? $element.find('.qlofd-room-timeline')
                : $element.closest('.qlofd-room-timeline');
            if (!$timeline.length) {
                return 0;
            }
            var rect = $timeline.get(0).getBoundingClientRect();
            var index = (pageX - rect.left) / rect.width * frontDesk.windowDays;
            return Math.max(0, Math.min(frontDesk.windowDays, index));
        },

        /* ----------------------------------------------- drag-across to create */

        initCellCreateSelection: function () {
            $('.qlofd-cells-layer').on('mousedown', '.qlofd-date-cell', function (e) {
                if (e.which !== 1) {
                    return;
                }
                frontDesk.cellSelection = {
                    row: $(this).closest('.qlofd-room-row'),
                    start: $(this).data('date'),
                    end: $(this).data('date'),
                    startCell: this
                };
                $(this).addClass('qlofd-empty-cell-create');
                return false;
            });

            $(document).on('mousemove.qlofdCells', function (e) {
                if (!frontDesk.cellSelection) {
                    return;
                }
                var $cell = $(document.elementFromPoint(e.clientX, e.clientY)).closest('.qlofd-date-cell');
                if (!$cell.length || !frontDesk.cellSelection.row.find('.qlofd-cells-layer').has($cell).length) {
                    return;
                }
                var newEnd = $cell.data('date');
                if (newEnd === frontDesk.cellSelection.end) {
                    return;
                }
                frontDesk.cellSelection.end = newEnd;
                frontDesk.cellSelection.row.find('.qlofd-date-cell').removeClass('qlofd-empty-cell-create');
                var start = new Date(frontDesk.cellSelection.start);
                var end = new Date(frontDesk.cellSelection.end);
                if (end < start) {
                    var tmp = start; start = end; end = tmp;
                }
                frontDesk.cellSelection.row.find('.qlofd-date-cell').each(function () {
                    var d = new Date($(this).data('date'));
                    if (d >= start && d <= end) {
                        $(this).addClass('qlofd-empty-cell-create');
                    }
                });
            });

            $(document).on('mouseup.qlofdCells', function () {
                if (!frontDesk.cellSelection) {
                    return;
                }
                var sel = frontDesk.cellSelection;
                frontDesk.cellSelection = null;
                frontDesk.cellSelection.row.find('.qlofd-date-cell').removeClass('qlofd-empty-cell-create');

                var dateFrom = sel.start < sel.end ? sel.start : sel.end;
                var dateTo = sel.start < sel.end ? frontDesk.addDays(sel.end, 1) : frontDesk.addDays(sel.start, 1);
                if (dateFrom === dateTo) {
                    return;
                }

                var idRoom = parseInt(sel.row.attr('data-room-id'), 10);

                if ($('#qlofd-filters').hasClass('qlofd-ooo-active')) {
                    var reason = window.prompt(frontDesk.tr('oooPromptReason', 'Reason for out-of-order:'));
                    if (reason === null) {
                        return;
                    }
                    frontDesk.ajaxAction('toggleOoo', {
                        id_room: idRoom,
                        date_from: dateFrom,
                        date_to: dateTo,
                        reason: reason,
                        mode: 'add'
                    });
                    return;
                }

                var firstType = $('.qlofd-room-row[data-room-id="' + idRoom + '"]').attr('data-room-type');
                frontDesk.openCreateModal(
                    parseInt($('#qlofd-hotel').val(), 10) || 0,
                    firstType ? parseInt(firstType, 10) : 0,
                    dateFrom,
                    dateTo,
                    idRoom
                );
            });
        },

        /* --------------------------------------------------- booking context */

        initBarContext: function () {
            $('.qlofd-bar').off('click.qlofdCtx').on('click.qlofdCtx', function (e) {
                e.preventDefault();
                e.stopPropagation();
                frontDesk.openBarContext($(this), e);
            });
        },

        openBarContext: function ($bar, e) {
            frontDesk.closeBarContext();
            var bookingId = $bar.attr('data-booking-id');
            var $pop = $('<div class="qlofd-hk-pop"></div>').css({
                left: Math.min(e.pageX, $(window).width() - 190),
                top: Math.min(e.pageY, $(window).height() - 260)
            });

            $pop.append('<strong class="qlofd-bar-title"></strong>');

            var actions = [
                { key: 'checkin', label: frontDesk.tr('btnCheckIn', 'Check-In'), icon: 'icon-sign-in', cls: 'btn-success' },
                { key: 'checkout', label: frontDesk.tr('btnCheckOut', 'Check-Out'), icon: 'icon-sign-out', cls: 'btn-primary' },
                { key: 'noshow', label: frontDesk.tr('btnNoShow', 'No-Show'), icon: 'icon-eye-slash', cls: 'btn-warning' },
                { key: 'cancel', label: frontDesk.tr('btnCancel', 'Cancel'), icon: 'icon-times', cls: 'btn-danger', confirm: true },
                { key: 'folio', label: frontDesk.tr('btnFolio', 'Folio'), icon: 'icon-file-text', cls: 'btn-info' },
                { key: 'note', label: frontDesk.tr('btnNote', 'Note'), icon: 'icon-edit', cls: 'btn-default' }
            ];

            actions.forEach(function (action) {
                var $btn = $('<button class="btn btn-xs ' + action.cls + '"><i class="' + action.icon + '"></i> ' + action.label + '</button>');
                $btn.on('click', function () {
                    frontDesk.closeBarContext();
                    if (action.key === 'folio') {
                        if (typeof frontDesk.openFolio === 'function') {
                            frontDesk.openFolio(parseInt(bookingId, 10));
                        }
                        return;
                    }
                    if (action.key === 'note') {
                        frontDesk.promptNote(bookingId);
                        return;
                    }
                    if (action.key === 'cancel' && !window.confirm(frontDesk.tr('confirmCancel', 'Cancel this booking?'))) {
                        return;
                    }
                    frontDesk.ajaxAction('setBookingStatus', { id_htl_booking: bookingId, action: action.key });
                });
                $pop.append($btn);
            });

            $pop.append('<button class="btn btn-xs btn-default qlofd-bar-lock-btn"></button>');
            $pop.find('.qlofd-bar-lock-btn').on('click', function () {
                frontDesk.closeBarContext();
                frontDesk.ajaxAction('toggleLockAssignment', { id_htl_booking: bookingId });
            });

            $('body').append($pop);
            var isLocked = $bar.hasClass('qlofd-bar-locked');
            $pop.find('.qlofd-bar-lock-btn').html(
                '<i class="icon-' + (isLocked ? 'unlock' : 'lock') + '"></i> ' + (isLocked
                    ? frontDesk.tr('btnUnlock', 'Unlock Assignment')
                    : frontDesk.tr('btnLock', 'Lock Assignment'))
            );
            $pop.find('.qlofd-bar-title').text($bar.find('.qlofd-bar-name').text());
        },

        closeBarContext: function () {
            $('.qlofd-hk-pop').remove();
        },

        promptNote: function (bookingId) {
            var note = window.prompt(frontDesk.tr('promptNote', 'Booking note:'));
            if (note === null) {
                return;
            }
            frontDesk.ajaxAction('updateBookingNote', { id_htl_booking: bookingId, note: note });
        },

        /* ----------------------------------------------------------- new booking */

        initNewBookingButton: function () {
            var $btn = $('<button type="button" class="btn btn-primary" id="qlofd-new-booking-btn">' +
                '<i class="icon-plus"></i> ' + frontDesk.tr('btnNewBooking', 'New Booking') + '</button>');
            $('.panel-heading .qlofd-legend').length
                ? $('.qlofd-legend').before($btn)
                : $('#qlofd-filters .qlofd-filter-actions .btn-group').after($btn);

            $btn.on('click', function () {
                var hotel = parseInt($('#qlofd-hotel').val(), 10) || 0;
                frontDesk.openCreateModal(hotel, 0, frontDesk.dateFrom, frontDesk.addDays(frontDesk.dateFrom, 1), 0);
            });
        },

        openCreateModal: function (idHotel, idProduct, dateFrom, dateTo, preferredRoom) {
            $('#qlofd-modal-title').text('New Booking');
            $('#qlofd-modal-body').html('<div class="text-center"><i class="icon-spinner icon-spin"></i></div>');
            $('#qlofd-booking-modal').modal('show');

            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: {
                    ajax: 1,
                    action: 'getBookingModalData',
                    id_hotel: idHotel,
                    id_product: idProduct,
                    date_from: dateFrom,
                    date_to: dateTo
                },
                dataType: 'json',
                success: function (response) {
                    if (!response.success) {
                        $('#qlofd-modal-body').html('<div class="alert alert-danger">' + (response.message || 'Error') + '</div>');
                        return;
                    }
                    $('#qlofd-modal-body').html(response.modal_content);
                    frontDesk.initCreateModal(dateFrom, dateTo, preferredRoom);
                }
            });
        },

        initCreateModal: function (dateFrom, dateTo, preferredRoom) {
            $('#qlofd-new-date-from').val(frontDesk.toDMY(frontDesk.parseISO(dateFrom))).datepicker({
                dateFormat: 'dd-mm-yy',
                onSelect: function (d) {
                    $('#qlofd-new-date-from-iso').val(frontDesk.toISO(frontDesk.parseDMY(d)));
                    frontDesk.syncRoomsInCreateModal();
                }
            });
            $('#qlofd-new-date-to').val(frontDesk.toDMY(frontDesk.parseISO(dateTo))).datepicker({
                dateFormat: 'dd-mm-yy',
                onSelect: function (d) {
                    $('#qlofd-new-date-to-iso').val(frontDesk.toISO(frontDesk.parseDMY(d)));
                    frontDesk.syncRoomsInCreateModal();
                }
            });
            $('#qlofd-new-date-from-iso').val(dateFrom);
            $('#qlofd-new-date-to-iso').val(dateTo);

            $('#qlofd-new-room-type').on('change', frontDesk.syncRoomsInCreateModal);
            frontDesk.syncRoomsInCreateModal();
            if (preferredRoom) {
                $('#qlofd-new-room').val(preferredRoom);
            }

            frontDesk.initGuestSearch();
            $('.qlofd-pick-suggested').on('click', function (e) {
                e.preventDefault();
                $('#qlofd-new-room').val($(this).data('room'));
            });

            $('#qlofd-booking-form').off('submit.qlofdCreate').on('submit.qlofdCreate', function (e) {
                e.preventDefault();
                frontDesk.submitCreateBooking();
            });
        },

        syncRoomsInCreateModal: function () {
            var idProduct = parseInt($('#qlofd-new-room-type').val(), 10) || 0;
            var $roomSelect = $('#qlofd-new-room').empty();
            $roomSelect.append('<option value="0">' + frontDesk.tr('selectRoom', 'Select room') + '</option>');

            var $typesData = $('#qlofd-available-types');
            if (!$typesData.length) {
                return;
            }
            var list;
            try {
                list = JSON.parse($typesData.text())[idProduct] || [];
            } catch (e) {
                return;
            }
            list.forEach(function (room) {
                $roomSelect.append('<option value="' + room.id_room + '">' + room.room_num + '</option>');
            });
        },

        initGuestSearch: function () {
            var timer;
            $('#qlofd-guest-search').off('keyup.qlofdGuest').on('keyup.qlofdGuest', function () {
                var q = $(this).val();
                clearTimeout(timer);
                if (q.length < 2) {
                    $('#qlofd-guest-results').empty();
                    return;
                }
                timer = setTimeout(function () {
                    $.ajax({
                        url: frontDesk.gridUrl.toString(),
                        method: 'POST',
                        data: { ajax: 1, action: 'searchGuest', query: q },
                        dataType: 'json',
                        success: function (response) {
                            var $box = $('#qlofd-guest-results').empty();
                            if (!response.success || !response.guests.length) {
                                $box.html('<div class="alert alert-info">No matching guest</div>');
                                return;
                            }
                            response.guests.forEach(function (guest) {
                                var $option = $('<a href="#">').addClass('list-group-item qlofd-guest-option');
                                $option.attr('data-id', guest.id_customer);
                                var guestName = guest.firstname + ' ' + guest.lastname;
                                $option.attr('data-name', guestName);
                                $option.append($('<span>').text(guestName));
                                $option.append($('<em>').text(guest.email));
                                $option.on('click', function (e) {
                                    e.preventDefault();
                                    $('#qlofd-id-customer').val($(this).data('id'));
                                    $('#qlofd-guest-selected').text($(this).data('name')).show();
                                    $('#qlofd-guest-results').empty();
                                })
                                .appendTo($box);
                            });
                        }
                    });
                }, 300);
            });
        },

        submitCreateBooking: function () {
            var $form = $('#qlofd-booking-form');
            var data = frontDesk.serializeForm($form);

            if (!parseInt(data.id_customer, 10) && (!data.firstname || !data.lastname || !data.email)) {
                frontDesk.showModalError(frontDesk.tr('errSelectGuest', 'Select an existing guest or fill the new guest details.'));
                return;
            }
            if (!parseInt(data.id_product, 10) || !parseInt(data.id_room, 10)) {
                frontDesk.showModalError(frontDesk.tr('errSelectRoom', 'Select a room type and a room.'));
                return;
            }
            if (!data.date_from || !data.date_to) {
                frontDesk.showModalError(frontDesk.tr('errSelectDates', 'Select check-in and check-out dates.'));
                return;
            }

            var $btn = $form.find('[type="submit"]');
            if ($btn.length) {
                $btn.prop('disabled', true);
            }

            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: $.extend({ ajax: 1, action: 'createBooking' }, data),
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $('#qlofd-booking-modal').modal('hide');
                        frontDesk.refreshGrid();
                    } else {
                        frontDesk.showModalError(response.message || frontDesk.tr('errBookingFailed', 'Error creating booking.'));
                        if ($btn.length) {
                            $btn.prop('disabled', false);
                        }
                    }
                }
            });
        },

        serializeForm: function ($form) {
            var data = {};
            $form.find('[name]').each(function () {
                var $el = $(this);
                if ($el.is(':checkbox')) {
                    data[$el.attr('name')] = $el.prop('checked') ? $el.val() : 0;
                } else {
                    data[$el.attr('name')] = $el.val();
                }
            });
            return data;
        },

        showModalError: function (message) {
            var $err = $('#qlofd-booking-form .qlofd-modal-error');
            if (!$err.length) {
                $err = $('<div class="alert alert-danger qlofd-modal-error"></div>').prependTo('#qlofd-booking-form');
            }
            $err.text(message);
        },

        /* ----------------------------------------------- housekeeping & OOO */

        initHousekeeping: function () {
            $('.qlofd-hk-badge').off('click.qlofdHk').on('click.qlofdHk', function (e) {
                e.stopPropagation();
                frontDesk.closeBarContext();
                frontDesk.openHousekeepingPop($(this), e);
            });

            $('.qlofd-ooo-bar').off('dblclick.qlofdOoo').on('dblclick.qlofdOoo', function (e) {
                e.stopPropagation();
                if (!window.confirm(frontDesk.tr('oooRemoveConfirm', 'Remove this out-of-order range?'))) {
                    return;
                }
                var $bar = $(this);
                frontDesk.ajaxAction('toggleOoo', {
                    id_room: $bar.closest('.qlofd-room-row').attr('data-room-id'),
                    date_from: $bar.attr('data-date-from'),
                    date_to: $bar.attr('data-date-to'),
                    mode: 'remove'
                });
            });
        },

        openHousekeepingPop: function ($badge, e) {
            frontDesk.closeBarContext();
            var idRoom = parseInt($badge.attr('data-room-id'), 10);
            var $pop = $('<div class="qlofd-hk-pop"></div>').css({
                left: Math.min(e.pageX, $(window).width() - 160),
                top: Math.min(e.pageY, $(window).height() - 220)
            });

            var statuses = [
                { key: 'clean', label: frontDesk.tr('hkClean', 'Clean'), cls: 'btn-success' },
                { key: 'dirty', label: frontDesk.tr('hkDirty', 'Dirty'), cls: 'btn-danger' },
                { key: 'inspected', label: frontDesk.tr('hkInspected', 'Inspected'), cls: 'btn-primary' },
                { key: 'do_not_disturb', label: frontDesk.tr('hkDnd', 'Do Not Disturb'), cls: 'btn-warning' }
            ];

            statuses.forEach(function (s) {
                var $btn = $('<button class="btn btn-xs ' + s.cls + '">' + s.label + '</button>');
                $btn.on('click', function () {
                    $pop.remove();
                    frontDesk.ajaxAction('setHousekeeping', { id_room: idRoom, status: s.key }, function () {
                        $('.qlofd-room-row[data-room-id="' + idRoom + '"] .qlofd-hk-badge')
                            .removeClass('qlofd-hk-clean qlofd-hk-dirty qlofd-hk-inspected qlofd-hk-do_not_disturb')
                            .addClass('qlofd-hk-' + s.key)
                            .text(s.label.charAt(0).toUpperCase());
                    });
                });
                $pop.append($btn);
            });

            $pop.appendTo('body');
        },

        initOooMode: function () {
            var $btn = $('<button type="button" class="btn btn-warning" id="qlofd-ooo-mode-btn">' +
                '<i class="icon-ban"></i> ' + frontDesk.tr('btnOooMode', 'OOO Mode') + '</button>');
            $('#qlofd-new-booking-btn').after($btn);

            $btn.on('click', function () {
                var active = !$('#qlofd-filters').hasClass('qlofd-ooo-active');
                $('#qlofd-filters').toggleClass('qlofd-ooo-active', active);
                $('.qlofd-grid').toggleClass('qlofd-ooo-mode', active);
                $(this).toggleClass('btn-success', active).toggleClass('btn-warning', !active);
                $(this).attr('title', active ? frontDesk.tr('btnOooModeOn', 'OOO Mode (click dates on a room to set out of order)') : '');
                $('.qlofd-grid').find('.qlofd-ooo-mode-hint').remove();
                if (active) {
                    $('.qlofd-grid').prepend('<div class="alert alert-warning qlofd-ooo-mode-hint">' +
                        '<i class="icon-ban"></i> ' + frontDesk.tr('btnOooModeOn', 'OOO Mode (click dates on a room to set out of order)') +
                        '</div>');
                }
            });
        },

        /* ----------------------------------------------------------- ajax helper */

        ajaxAction: function (action, data, callback) {
            var payload = $.extend({ ajax: 1, action: action }, data);
            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: payload,
                dataType: 'json',
                success: function (response) {
                    if (!response.success) {
                        window.alert(response.message || frontDesk.tr('errActionFailed', 'Action failed.'));
                    } else if (callback) {
                        callback(response);
                    }
                    frontDesk.refreshGrid();
                }
            });
        },

        /* -------------------------------------------------------------- folio */

        openFolio: function (idHtlBooking) {
            $('#qlofd-folio-content').html('<div class="modal-body text-center"><i class="icon-spinner icon-spin"></i></div>');
            $('#qlofd-folio-modal').modal('show');

            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: { ajax: 1, action: 'getFolio', id_htl_booking: idHtlBooking },
                dataType: 'json',
                success: function (response) {
                    if (!response.success) {
                        $('#qlofd-folio-content').html(
                            '<div class="modal-body"><div class="alert alert-danger">' + (response.message || 'Error') + '</div></div>'
                        );
                        return;
                    }
                    $('#qlofd-folio-content').html(response.html);
                    frontDesk.initFolioActions();
                }
            });
        },

        initFolioActions: function () {
            var $content = $('#qlofd-folio-content');
            var idHtlBooking = parseInt($content.find('.qlofd-folio-booking-id').val(), 10) || 0;

            $content.off('click.qlofdFolio').on('click.qlofdFolio', '.qlofd-line-add', function () {
                var label = $content.find('.qlofd-line-new-label').val();
                var qty = parseInt($content.find('.qlofd-line-new-qty').val(), 10) || 1;
                var price = parseFloat($content.find('.qlofd-line-new-price').val()) || 0;
                var type = $content.find('.qlofd-line-new-type').val();

                frontDesk.folioAction('folioAddLine', {
                    id_htl_booking: idHtlBooking,
                    label: label,
                    qty: qty,
                    unit_price: price,
                    type: type
                });
            });

            $content.off('click.qlofdFolioEdit').on('click.qlofdFolioEdit', '.qlofd-line-edit', function () {
                var $row = $(this).closest('.qlofd-folio-line-row');
                var idLine = $row.attr('data-line-id');
                var label = window.prompt('Item:', $row.find('.qlofd-line-label').text().trim());
                if (label === null) {
                    return;
                }
                var qty = parseInt(window.prompt('Qty:', $row.find('.qlofd-line-qty').text().trim()), 10) || 1;
                var price = parseFloat(window.prompt('Price:', $row.find('.qlofd-line-price').text().replace(/[^0-9.-]/g, ''))) || 0;

                frontDesk.folioAction('folioUpdateLine', {
                    id_line: idLine,
                    label: label,
                    qty: qty,
                    unit_price: price
                });
            });

            $content.off('click.qlofdFolioDel').on('click.qlofdFolioDel', '.qlofd-line-delete', function () {
                if (!window.confirm('Delete this line?')) {
                    return;
                }
                frontDesk.folioAction('folioDeleteLine', { id_line: $(this).closest('.qlofd-folio-line-row').attr('data-line-id') });
            });

            $content.off('click.qlofdFolioPay').on('click.qlofdFolioPay', '.qlofd-pay-add', function () {
                frontDesk.folioAction('addPayment', {
                    id_htl_booking: idHtlBooking,
                    amount: $content.find('.qlofd-pay-amount').val(),
                    payment_method: $content.find('.qlofd-pay-method').val(),
                    reference: $content.find('.qlofd-pay-reference').val()
                });
            });

            $content.off('click.qlofdFolioPrint').on('click.qlofdFolioPrint', '.qlofd-receipt-print', function () {
                var form = $('<form method="POST" action="' + frontDesk.gridUrl.toString() + '">' +
                    '<input type="hidden" name="ajax" value="1">' +
                    '<input type="hidden" name="action" value="printReceipt">' +
                    '<input type="hidden" name="id_htl_booking" value="' + idHtlBooking + '"></form>');
                $('body').append(form);
                form.submit();
                form.remove();
            });
        },

        folioAction: function (action, data) {
            var payload = $.extend({ ajax: 1, action: action }, data);
            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: payload,
                dataType: 'json',
                success: function (response) {
                    if (response.success && response.html) {
                        $('#qlofd-folio-content').html(response.html);
                        frontDesk.initFolioActions();
                        if (response.folio && response.folio.payment_status) {
                            frontDesk.refreshGrid();
                        }
                    } else {
                        window.alert(response.message || frontDesk.tr('errActionFailed', 'Action failed.'));
                    }
                }
            });
        },

        /* ------------------------------------------------- undo & shortcuts */

        initUndoButton: function () {
            var $btn = $('<button type="button" class="btn btn-default" id="qlofd-undo-btn" title="Ctrl+Z">' +
                '<i class="icon-undo"></i> ' + frontDesk.tr('btnUndo', 'Undo') + '</button>');
            $('#qlofd-new-booking-btn').after($btn);

            $btn.on('click', frontDesk.undoLastAction);
        },

        undoLastAction: function () {
            $.ajax({
                url: frontDesk.gridUrl.toString(),
                method: 'POST',
                data: { ajax: 1, action: 'undo' },
                dataType: 'json',
                success: function (response) {
                    if (!response.success) {
                        window.alert(response.message || frontDesk.tr('errNothingToUndo', 'Nothing to undo.'));
                    }
                    frontDesk.refreshGrid();
                }
            });
        },

        initShortcuts: function () {
            $(document).on('keydown.qlofdShortcuts', function (e) {
                if (!e.ctrlKey && !e.metaKey) {
                    return;
                }
                var $target = $(e.target);
                if ($target.is('input, textarea, select')) {
                    return;
                }
                if (e.key === 'z' || e.key === 'Z') {
                    e.preventDefault();
                    frontDesk.undoLastAction();
                } else if (e.key === 'n' || e.key === 'N') {
                    e.preventDefault();
                    var hotel = parseInt($('#qlofd-hotel').val(), 10) || 0;
                    frontDesk.openCreateModal(hotel, 0, frontDesk.dateFrom, frontDesk.addDays(frontDesk.dateFrom, 1), 0);
                }
            });
        },

        /* --------------------------------------------------------------- modals */

        initModals: function () {
            $(document).on('click', '.qlofd-hk-pop', function (e) {
                e.stopPropagation();
            });
            $(document).on('click', function () {
                frontDesk.closeBarContext();
            });
            $('#qlofd-booking-modal').on('hidden.bs.modal', function () {
                $('#qlofd-modal-body').empty();
            });
        }
    };

    $(document).ready(function () {
        frontDesk.init();
    });
})(jQuery);