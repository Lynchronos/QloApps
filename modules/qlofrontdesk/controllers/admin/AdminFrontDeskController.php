<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License version 3.0
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/license/osl-3-0-php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@qloapps.com so we can send you a copy immediately.
 */

class AdminFrontDeskController extends ModuleAdminController
{
    protected $idHotel = 0;
    protected $idRoomType = 0;
    protected $dateFrom = '';

    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();

        parent::__construct();
    }

    public function init()
    {
        parent::init();

        $this->idHotel = (int) Tools::getValue('id_hotel');
        $this->idRoomType = (int) Tools::getValue('id_room_type');
        $this->dateFrom = Tools::getValue('date_from');

        if (!Validate::isDate($this->dateFrom)) {
            $this->dateFrom = date('Y-m-d');
        }
    }

    public function setMedia()
    {
        parent::setMedia();

        $this->addJqueryUI(array('ui.draggable', 'ui.droppable'));
        $this->addJqueryUI('ui.datepicker');

        $this->addCSS(_PS_MODULE_DIR_.$this->module->name.'/views/css/admin/front_desk.css');
        $this->addJS(_PS_MODULE_DIR_.$this->module->name.'/views/js/admin/front_desk.js');

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        MediaCore::addJsDef(array(
            'qlofdGridUrl' => $this->context->link->getAdminLink('AdminFrontDesk'),
            'qlofdCurrencySign' => $currency->sign,
            'qlofdCurrencyPrefix' => $currency->prefix,
            'qlofdCurrencySuffix' => $currency->suffix,
            'qlofdCurrencyFormat' => $currency->format,
            'qlofdGroupThreshold' => (int) Configuration::get('QLOFD_GROUP_BOOKING_THRESHOLD'),
            'qlofdTranslations' => array(
                'btnCheckIn' => $this->l('Check-In'),
                'btnCheckOut' => $this->l('Check-Out'),
                'btnNoShow' => $this->l('No-Show'),
                'btnCancel' => $this->l('Cancel'),
                'btnFolio' => $this->l('Folio'),
                'btnNote' => $this->l('Note'),
                'btnLock' => $this->l('Lock Assignment'),
                'btnUnlock' => $this->l('Unlock Assignment'),
                'btnNewBooking' => $this->l('New Booking'),
                'confirmCancel' => $this->l('Cancel this booking?'),
                'promptNote' => $this->l('Booking note:'),
                'errSelectGuest' => $this->l('Select an existing guest or fill the new guest details.'),
                'errSelectRoom' => $this->l('Select a room type and a room.'),
                'errSelectDates' => $this->l('Select check-in and check-out dates.'),
                'errBookingFailed' => $this->l('Error creating booking.'),
                'errActionFailed' => $this->l('Action failed.'),
                'payAtHotel' => $this->l('Pay at Hotel'),
                'saveBooking' => $this->l('Save Booking'),
                'selectRoom' => $this->l('Select room'),
                'hkClean' => $this->l('Clean'),
                'hkDirty' => $this->l('Dirty'),
                'hkInspected' => $this->l('Inspected'),
                'hkDnd' => $this->l('Do Not Disturb'),
                'btnOooMode' => $this->l('OOO Mode'),
                'btnOooModeOn' => $this->l('OOO Mode (click dates on a room to set out of order)'),
                'oooPromptReason' => $this->l('Reason for out-of-order:'),
                'oooRemoveConfirm' => $this->l('Remove this out-of-order range?'),
                'errNoOooRange' => $this->l('No out-of-order range selected.'),
                'btnUndo' => $this->l('Undo'),
                'errNothingToUndo' => $this->l('Nothing to undo.'),
            ),
        ));
    }

    public function initContent()
    {
        if (!$this->tabAccess['view']) {
            $this->errors[] = $this->l('You do not have permission to view this.');
            return;
        }

        $this->toolbar_title = $this->l('Front Desk');
        $this->display = 'view';

        parent::initContent();
    }

    public function renderView()
    {
        $objGrid = new QlofdGrid($this->idHotel, $this->idRoomType, $this->dateFrom);
        $idProfile = (int) $this->context->employee->id_profile;
        $gridData = $objGrid->getGridData($idProfile);

        $this->idHotel = $gridData['date_from'] ? $objGrid->resolveHotel($idProfile, $this->idHotel) : $this->idHotel;
        $windowStart = $gridData['date_from'];

        $bars = $this->buildBarLayout($gridData);
        $oooLayout = $this->buildOooLayout($gridData);

        $barsByRoom = array();
        foreach ($bars as $bar) {
            $barsByRoom[(int) $bar['id_room']][] = $bar;
        }

        $oooByRoom = array();
        foreach ($oooLayout as $ooo) {
            $oooByRoom[(int) $ooo['id_room']][] = $ooo;
        }

        $this->tpl_view_vars = array_merge($this->tpl_view_vars, array(
            'hotels' => $objGrid->getHotels($idProfile),
            'id_hotel' => $this->idHotel,
            'id_room_type' => $this->idRoomType,
            'date_from' => $windowStart,
            'date_to' => $gridData['date_to'],
            'window_days' => count($gridData['dates']),
            'dates' => $gridData['dates'],
            'rooms' => $gridData['rooms'],
            'room_types' => $gridData['room_types'],
            'booking_bars' => $barsByRoom,
            'ooo_layout' => $oooByRoom,
            'group_threshold' => $gridData['groupIdThreshold'],
            'link' => $this->context->link,
        ));

        return parent::renderView();
    }

    /**
     * Compute left/width percentages for booking bars over the window.
     *
     * @param array $gridData
     * @return array
     */
    protected function buildBarLayout($gridData)
    {
        $bars = array();
        $windowStart = strtotime($gridData['date_from']);
        $days = count($gridData['dates']);
        $daySeconds = 86400;

        foreach ($gridData['booking_bars'] as $bar) {
            $from = strtotime($bar['date_from']);
            $to = strtotime($bar['date_to']);

            $leftIndex = max(0, (int) floor(($from - $windowStart) / $daySeconds));
            $rightIndex = min($days, (int) ceil(($to - $windowStart) / $daySeconds));
            $nights = max(1, $rightIndex - $leftIndex);

            $bar['left'] = round($leftIndex / $days * 100, 3);
            $bar['width'] = round($nights / $days * 100, 3);
            $bar['nights'] = $nights;
            $bar['starts_before'] = $from < $windowStart;
            $bar['ends_after'] = $to > strtotime($gridData['date_to'].' +1 day');
            $bars[] = $bar;
        }

        return $bars;
    }

    /**
     * Compute left/width percentages for OOO ranges over the window.
     *
     * @param array $gridData
     * @return array
     */
    protected function buildOooLayout($gridData)
    {
        $layout = array();
        $windowStart = strtotime($gridData['date_from']);
        $days = count($gridData['dates']);
        $daySeconds = 86400;

        foreach ($gridData['ooo'] as $ooo) {
            $from = strtotime($ooo['date_from']);
            $to = strtotime($ooo['date_to'].' +1 day');

            $leftIndex = max(0, (int) floor(($from - $windowStart) / $daySeconds));
            $rightIndex = min($days, (int) ceil(($to - $windowStart) / $daySeconds));
            $span = max(1, $rightIndex - $leftIndex);

            $ooo['left'] = round($leftIndex / $days * 100, 3);
            $ooo['width'] = round($span / $days * 100, 3);
            $layout[] = $ooo;
        }

        return $layout;
    }

    /**
     * JSON payload for optimistic refresh of the grid.
     *
     * @return void
     */
    public function ajaxProcessGetGridData()
    {
        $response = array('success' => false);
        if (!$this->tabAccess['view']) {
            $response['error'] = $this->l('You do not have permission to view this.');
            $this->ajaxDie(json_encode($response));
        }

        $objGrid = new QlofdGrid(
            (int) Tools::getValue('id_hotel'),
            (int) Tools::getValue('id_room_type'),
            Tools::getValue('date_from')
        );
        $gridData = $objGrid->getGridData((int) $this->context->employee->id_profile);

        $response['success'] = true;
        $response['data'] = array(
            'date_from' => $gridData['date_from'],
            'date_to' => $gridData['date_to'],
            'dates' => $gridData['dates'],
            'rooms' => $gridData['rooms'],
            'booking_bars' => $this->buildBarLayout($gridData),
            'ooo' => $this->buildOooLayout($gridData),
        );

        $this->ajaxDie(json_encode($response));
    }

    /**
     * Guard-less execution wrapper for mutating actions.
     *
     * @param callable $callback
     * @return void
     */
    protected function runMutation($callback)
    {
        if ($this->tabAccess['edit'] !== 1) {
            $this->ajaxDie(json_encode(array(
                'success' => false,
                'message' => $this->l('You do not have permission to edit this.'),
            )));
        }

        $result = call_user_func($callback);
        $this->ajaxDie(json_encode($result));
    }

    public function ajaxProcessCreateBooking()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->createBooking(array(
                'id_customer' => (int) Tools::getValue('id_customer'),
                'email' => Tools::getValue('email'),
                'firstname' => Tools::getValue('firstname'),
                'lastname' => Tools::getValue('lastname'),
                'phone' => Tools::getValue('phone'),
                'id_hotel' => (int) Tools::getValue('id_hotel'),
                'id_product' => (int) Tools::getValue('id_product'),
                'id_room' => (int) Tools::getValue('id_room'),
                'date_from' => Tools::getValue('date_from'),
                'date_to' => Tools::getValue('date_to'),
                'adults' => (int) Tools::getValue('adults', 2),
                'children' => (int) Tools::getValue('children', 0),
                'child_ages' => Tools::getValue('child_ages'),
                'deposit' => Tools::getValue('deposit'),
                'payment_method' => Tools::getValue('payment_method'),
                'payment_type' => (int) Tools::getValue('payment_type'),
                'reference' => Tools::getValue('reference'),
                'comment' => Tools::getValue('comment'),
                'is_group_booking' => (int) Tools::getValue('is_group_booking'),
            ));
        });
    }

    public function ajaxProcessUpdateBookingDates()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->updateBookingDates(
                (int) Tools::getValue('id_htl_booking'),
                Tools::getValue('date_from'),
                Tools::getValue('date_to')
            );
        });
    }

    public function ajaxProcessMoveBookingRoom()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->moveBookingRoom(
                (int) Tools::getValue('id_htl_booking'),
                (int) Tools::getValue('id_room')
            );
        });
    }

    public function ajaxProcessSwapBooking()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->swapBooking(
                (int) Tools::getValue('id_htl_booking_from'),
                (int) Tools::getValue('id_htl_booking_to')
            );
        });
    }

    public function ajaxProcessSetBookingStatus()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->setBookingStatus(
                (int) Tools::getValue('id_htl_booking'),
                Tools::getValue('action'),
                Tools::getValue('status_date')
            );
        });
    }

    public function ajaxProcessToggleLockAssignment()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->toggleLockAssignment((int) Tools::getValue('id_htl_booking'));
        });
    }

    public function ajaxProcessUpdateBookingNote()
    {
        $this->runMutation(function () {
            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            return $objFlow->updateBookingNote(
                (int) Tools::getValue('id_htl_booking'),
                Tools::getValue('note')
            );
        });
    }

    public function ajaxProcessUndo()
    {
        $this->runMutation(function () {
            $undoItem = QlofdUndo::pop();
            if (!$undoItem) {
                return array('success' => false, 'message' => $this->l('Nothing to undo.'));
            }

            $objFlow = new QlofdBookingFlow((int) $this->context->employee->id);
            $result = $objFlow->applyUndo($undoItem);
            if (!$result['success']) {
                QlofdUndo::push($undoItem);
            }

            return $result;
        });
    }

    public function ajaxProcessRoomAssignSuggest()
    {
        $suggestion = QlofdRoomAssignment::suggest(
            (int) Tools::getValue('id_hotel'),
            (int) Tools::getValue('id_product'),
            Tools::getValue('date_from'),
            Tools::getValue('date_to'),
            (int) Tools::getValue('exclude_booking_id')
        );

        $this->ajaxDie(json_encode(array(
            'success' => empty($suggestion) ? false : true,
            'suggestion' => $suggestion,
        )));
    }

    /**
     * Guest search for the booking modal (Ajax live search).
     *
     * @return void
     */
    public function ajaxProcessSearchGuest()
    {
        $query = trim(Tools::getValue('query'));
        if (!$query || !Validate::isCleanHtml($query)) {
            $this->ajaxDie(json_encode(array('success' => false)));
        }

        $results = Db::getInstance()->executeS(
            'SELECT `id_customer`, `email`, `firstname`, `lastname`
             FROM `'._DB_PREFIX_.'customer`
             WHERE `deleted` = 0
             AND (`firstname` LIKE \'%'.pSQL($query).'%\'
                  OR `lastname` LIKE \'%'.pSQL($query).'%\'
                  OR `email` LIKE \'%'.pSQL($query).'%\')
             LIMIT 20'
        );

        $this->ajaxDie(json_encode(array(
            'success' => is_array($results),
            'guests' => $results ? $results : array(),
        )));
    }

    /**
     * Payload for the new-booking modal: room types + available rooms + rate.
     *
     * @return void
     */
    public function ajaxProcessGetBookingModalData()
    {
        $idHotel = (int) Tools::getValue('id_hotel');
        $idProduct = (int) Tools::getValue('id_product');
        $dateFrom = Tools::getValue('date_from');
        $dateTo = Tools::getValue('date_to');

        if (!Validate::isDate($dateFrom) || !Validate::isDate($dateTo)
            || strtotime($dateTo) <= strtotime($dateFrom)
        ) {
            $this->ajaxDie(json_encode(array('success' => false, 'message' => $this->l('Invalid dates.'))));
        }

        $objRoomType = new HotelRoomType();
        $roomTypes = $objRoomType->getRoomTypeByHotelId($idHotel, $this->context->language->id, 1);

        $objBookingDetail = new HotelBookingDetail();
        $suggested = array();
        $availableByType = array();
        if ($roomTypes) {
            foreach ($roomTypes as $roomType) {
                $rooms = $objBookingDetail->getAvailableRoomsForReallocation(
                    $dateFrom,
                    $dateTo,
                    (int) $roomType['id_product'],
                    $idHotel
                );
                $availableByType[(int) $roomType['id_product']] = $rooms ? $rooms : array();

                if (!$suggested && $rooms) {
                    $suggested = QlofdRoomAssignment::suggest($idHotel, (int) $roomType['id_product'], $dateFrom, $dateTo);
                }
            }
        }

        $totalPrice = array();
        if ($idProduct) {
            $occupancy = array(array('adults' => (int) Tools::getValue('adults', 2), 'children' => (int) Tools::getValue('children', 0), 'child_ages' => array()));
            $totalPrice = HotelRoomTypeFeaturePricing::getRoomTypeTotalPrice(
                $idProduct,
                $dateFrom,
                $dateTo,
                $occupancy,
                0,
                0,
                0,
                0,
                0,
                1
            );
        }

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->context->smarty->assign(array(
            'id_hotel' => $idHotel,
            'room_types' => $roomTypes ? $roomTypes : array(),
            'available_by_type' => $availableByType,
            'available_types_json' => json_encode($availableByType),
            'suggested_room' => $suggested,
            'total_price' => $totalPrice,
            'currency_sign' => $currency->sign,
            'link' => $this->context->link,
        ));

        $this->ajaxDie(json_encode(array(
            'success' => true,
            'modal_content' => $this->context->smarty->fetch(
                _PS_MODULE_DIR_.$this->module->name.'/views/templates/admin/front_desk/_partials/booking-modal.tpl'
            ),
        )));
    }

    /**
     * Build the folio payload + rendered panel HTML.
     *
     * @param int $idHtlBooking
     * @return array
     */
    protected function buildFolioPanel($idHtlBooking)
    {
        $folio = (new QlofdFolioService((int) $idHtlBooking))->getFolioData();
        if (empty($folio)) {
            return array('success' => false, 'message' => $this->l('Folio not found.'));
        }

        $this->context->smarty->assign(array(
            'folio' => $folio,
            'default_currency' => new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT')),
            'link' => $this->context->link,
        ));

        return array(
            'success' => true,
            'id_htl_booking' => (int) $idHtlBooking,
            'folio' => $folio,
            'html' => $this->context->smarty->fetch(
                _PS_MODULE_DIR_.$this->module->name.'/views/templates/admin/front_desk/_partials/folio-panel.tpl'
            ),
        );
    }

    public function ajaxProcessGetFolio()
    {
        if ($this->tabAccess['view'] !== 1) {
            $this->ajaxDie(json_encode(array('success' => false, 'message' => $this->l('You do not have permission to view this.'))));
        }
        $this->ajaxDie(json_encode($this->buildFolioPanel((int) Tools::getValue('id_htl_booking'))));
    }

    public function ajaxProcessFolioAddLine()
    {
        $this->runMutation(function () {
            $idHtlBooking = (int) Tools::getValue('id_htl_booking');
            $objBooking = new HotelBookingDetail($idHtlBooking);
            if (!Validate::isLoadedObject($objBooking)) {
                return array('success' => false, 'message' => $this->l('Invalid booking.'));
            }

            $label = trim(Tools::getValue('label'));
            $qty = (int) Tools::getValue('qty', 1);
            $unitPrice = (float) Tools::getValue('unit_price', 0);
            $type = Tools::getValue('type') === 'credit' ? QlofdFolioLine::TYPE_CREDIT : QlofdFolioLine::TYPE_CHARGE;

            if (!$label || $qty < 1) {
                return array('success' => false, 'message' => $this->l('Invalid line item.'));
            }

            $ratio = 1.0;
            $objOrderDetail = new OrderDetail((int) $objBooking->id_order_detail);
            if (Validate::isLoadedObject($objOrderDetail) && (float) $objOrderDetail->unit_price_tax_incl > 0) {
                $ratio = (float) $objOrderDetail->unit_price_tax_excl / (float) $objOrderDetail->unit_price_tax_incl;
            }
            $unitTaxExcl = Tools::ps_round($unitPrice * $ratio, 6);

            $objLine = new QlofdFolioLine();
            $objLine->id_htl_booking = $idHtlBooking;
            $objLine->label = pSQL($label);
            $objLine->qty = $qty;
            $objLine->unit_price_tax_incl = Tools::ps_round($unitPrice, 6);
            $objLine->unit_price_tax_excl = $unitTaxExcl;
            $objLine->type = $type;
            $objLine->id_employee = (int) $this->context->employee->id;

            if (!$objLine->save()) {
                return array('success' => false, 'message' => $this->l('Unable to add folio line.'));
            }

            QlofdAuditLog::record('folio_line', (int) $objLine->id, 'add', null, array('label' => $label, 'type' => $type), (int) $this->context->employee->id);

            return $this->buildFolioPanel($idHtlBooking);
        });
    }

    public function ajaxProcessFolioUpdateLine()
    {
        $this->runMutation(function () {
            $idLine = (int) Tools::getValue('id_line');
            $objLine = new QlofdFolioLine($idLine);
            if (!Validate::isLoadedObject($objLine)) {
                return array('success' => false, 'message' => $this->l('Invalid line item.'));
            }

            $label = trim(Tools::getValue('label'));
            $qty = (int) Tools::getValue('qty', 1);
            $unitPrice = (float) Tools::getValue('unit_price', 0);

            if (!$label || $qty < 1) {
                return array('success' => false, 'message' => $this->l('Invalid line item.'));
            }

            $objBooking = new HotelBookingDetail((int) $objLine->id_htl_booking);
            $ratio = 1.0;
            if (Validate::isLoadedObject($objBooking)) {
                $objOrderDetail = new OrderDetail((int) $objBooking->id_order_detail);
                if (Validate::isLoadedObject($objOrderDetail) && (float) $objOrderDetail->unit_price_tax_incl > 0) {
                    $ratio = (float) $objOrderDetail->unit_price_tax_excl / (float) $objOrderDetail->unit_price_tax_incl;
                }
            }

            $objLine->label = pSQL($label);
            $objLine->qty = $qty;
            $objLine->unit_price_tax_incl = Tools::ps_round($unitPrice, 6);
            $objLine->unit_price_tax_excl = Tools::ps_round($unitPrice * $ratio, 6);

            if (!$objLine->save()) {
                return array('success' => false, 'message' => $this->l('Unable to update folio line.'));
            }

            return $this->buildFolioPanel((int) $objLine->id_htl_booking);
        });
    }

    public function ajaxProcessFolioDeleteLine()
    {
        $this->runMutation(function () {
            $idLine = (int) Tools::getValue('id_line');
            $objLine = new QlofdFolioLine($idLine);
            if (!Validate::isLoadedObject($objLine)) {
                return array('success' => false, 'message' => $this->l('Invalid line item.'));
            }

            $idHtlBooking = (int) $objLine->id_htl_booking;
            QlofdAuditLog::record('folio_line', $idLine, 'delete', array('label' => $objLine->label), null, (int) $this->context->employee->id);
            if (!$objLine->delete()) {
                return array('success' => false, 'message' => $this->l('Unable to delete folio line.'));
            }

            return $this->buildFolioPanel($idHtlBooking);
        });
    }

    public function ajaxProcessAddPayment()
    {
        $this->runMutation(function () {
            $idHtlBooking = (int) Tools::getValue('id_htl_booking');
            $objBooking = new HotelBookingDetail($idHtlBooking);
            if (!Validate::isLoadedObject($objBooking)) {
                return array('success' => false, 'message' => $this->l('Invalid booking.'));
            }

            $amount = (float) Tools::getValue('amount');
            $method = trim(Tools::getValue('payment_method'));
            $reference = trim(Tools::getValue('reference'));
            $paymentType = (int) Tools::getValue('payment_type', OrderPayment::PAYMENT_TYPE_PAY_AT_HOTEL);

            if ($amount <= 0) {
                return array('success' => false, 'message' => $this->l('Enter a valid payment amount.'));
            }
            if (!$method) {
                return array('success' => false, 'message' => $this->l('Payment method is required.'));
            }

            $objOrder = new Order((int) $objBooking->id_order);
            if (!Validate::isLoadedObject($objOrder)) {
                return array('success' => false, 'message' => $this->l('Order not found.'));
            }

            $currency = new Currency((int) $objOrder->id_currency);
            if (!$objOrder->addOrderPayment($amount, $method, $reference, $currency, null, null, $paymentType)) {
                return array('success' => false, 'message' => $this->l('Unable to record the payment.'));
            }

            $idOrderPayment = (int) Db::getInstance()->getValue(
                'SELECT MAX(`id_order_payment`) FROM `'._DB_PREFIX_.'order_payment`
                 WHERE `order_reference` = \''.pSQL($objOrder->reference).'\''
            );

            $objFolioPayment = new QlofdFolioPayment();
            $objFolioPayment->id_htl_booking = $idHtlBooking;
            $objFolioPayment->id_order_payment = $idOrderPayment;
            $objFolioPayment->amount = $amount;
            $objFolioPayment->payment_method = pSQL($method);
            $objFolioPayment->reference = pSQL($reference);
            $objFolioPayment->id_currency = (int) $objOrder->id_currency;
            $objFolioPayment->id_employee = (int) $this->context->employee->id;
            if (!$objFolioPayment->save()) {
                return array('success' => false, 'message' => $this->l('Payment saved but folio could not be updated.'));
            }

            QlofdBookingExtra::refreshPaymentStatus($idHtlBooking);
            QlofdAuditLog::record('folio_payment', (int) $objFolioPayment->id, 'add', null, array('amount' => $amount, 'method' => $method), (int) $this->context->employee->id);

            return $this->buildFolioPanel($idHtlBooking);
        });
    }

    public function ajaxProcessPrintReceipt()
    {
        if ($this->tabAccess['view'] !== 1) {
            $this->ajaxDie(json_encode(array('success' => false, 'message' => $this->l('You do not have permission to view this.'))));
        }
        $idHtlBooking = (int) Tools::getValue('id_htl_booking');
        $folio = (new QlofdFolioService($idHtlBooking))->getFolioData();
        if (empty($folio)) {
            $this->ajaxDie(json_encode(array('success' => false, 'message' => $this->l('Folio not found.'))));
        }

        $objReceipt = new QlofdReceiptPdf($folio, $this->context->smarty);
        $objReceipt->renderPdfAndOutput();
    }

    public function ajaxProcessSetHousekeeping()
    {
        $this->runMutation(function () {
            $idRoom = (int) Tools::getValue('id_room');
            $status = Tools::getValue('status');
            $note = Tools::getValue('note');

            $objRoom = new HotelRoomInformation($idRoom);
            if (!Validate::isLoadedObject($objRoom)) {
                return array('success' => false, 'message' => $this->l('Invalid room.'));
            }

            $allowed = array(
                QlofdHousekeepingLog::STATUS_CLEAN,
                QlofdHousekeepingLog::STATUS_DIRTY,
                QlofdHousekeepingLog::STATUS_INSPECTED,
                QlofdHousekeepingLog::STATUS_DO_NOT_DISTURB,
            );
            if (!in_array($status, $allowed)) {
                return array('success' => false, 'message' => $this->l('Invalid housekeeping status.'));
            }

            if (!QlofdHousekeepingLog::logStatus($idRoom, $status, $note, (int) $this->context->employee->id)) {
                return array('success' => false, 'message' => $this->l('Unable to update housekeeping status.'));
            }

            QlofdAuditLog::record('room', $idRoom, 'housekeeping', null, array('status' => $status, 'note' => $note), (int) $this->context->employee->id);

            return array('success' => true, 'status' => $status, 'id_room' => $idRoom);
        });
    }

    public function ajaxProcessToggleOoo()
    {
        $this->runMutation(function () {
            $idRoom = (int) Tools::getValue('id_room');
            $dateFrom = Tools::getValue('date_from');
            $dateTo = Tools::getValue('date_to');
            $reason = Tools::getValue('reason');
            $mode = Tools::getValue('mode', 'add');

            $objRoom = new HotelRoomInformation($idRoom);
            if (!Validate::isLoadedObject($objRoom)) {
                return array('success' => false, 'message' => $this->l('Invalid room.'));
            }

            if (!Validate::isDate($dateFrom) || !Validate::isDate($dateTo)
                || strtotime($dateTo) <= strtotime($dateFrom)
            ) {
                return array('success' => false, 'message' => $this->l('Invalid OOO dates.'));
            }

            $objDisable = new HotelRoomDisableDates();

            if ($mode === 'remove') {
                $result = $objDisable->deleteDisabledDatesForDateRange(array(
                    'id_room' => $idRoom,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ));
                if (!$result) {
                    return array('success' => false, 'message' => $this->l('No OOO range found.'));
                }
                QlofdAuditLog::record('room', $idRoom, 'ooo_remove', array('date_from' => $dateFrom, 'date_to' => $dateTo), null, (int) $this->context->employee->id);

                return array('success' => true, 'action' => 'removed');
            }

            if ($objDisable->checkIfRoomAlreadyDisabled(array(
                'id_room' => $idRoom,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ))) {
                return array('success' => false, 'message' => $this->l('This room is already out of order for part of the selected range.'));
            }

            $objDisable = new HotelRoomDisableDates();
            $objDisable->id_room_type = (int) $objRoom->id_product;
            $objDisable->id_room = (int) $idRoom;
            $objDisable->date_from = $dateFrom;
            $objDisable->date_to = $dateTo;
            $objDisable->reason = pSQL($reason, true);
            if (!$objDisable->save()) {
                return array('success' => false, 'message' => $this->l('Unable to set the room out of order.'));
            }

            QlofdAuditLog::record('room', $idRoom, 'ooo_add', null, array('date_from' => $dateFrom, 'date_to' => $dateTo, 'reason' => $reason), (int) $this->context->employee->id);

            return array('success' => true, 'action' => 'added');
        });
    }

    public function ajaxProcessGetAuditLog()
    {
        $logs = QlofdAuditLog::getLatest((int) Tools::getValue('limit', 100));
        $this->ajaxDie(json_encode(array(
            'success' => true,
            'logs' => $logs ? $logs : array(),
        )));
    }
}