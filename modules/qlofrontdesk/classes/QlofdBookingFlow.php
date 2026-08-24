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

/**
 * Booking operations: create/move/swap/dates/status/lock. Wraps core hotel
 * booking flow, writes audit log entries and pushes session-scoped undo items.
 */
class QlofdBookingFlow
{
    /** @var int */
    protected $idEmployee;

    /** @var Module */
    protected $module;

    public function __construct($idEmployee = 0)
    {
        $this->idEmployee = (int) $idEmployee;
        $this->module = Module::getInstanceByName('qlofrontdesk');
    }

    /**
     * Move a booking to another room (same room type).
     *
     * @param int $idHtlBooking
     * @param int $idRoom
     * @return array
     */
    public function moveBookingRoom($idHtlBooking, $idRoom)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        if ((int) $objBooking->id_room === (int) $idRoom) {
            return array('success' => true, 'message' => '');
        }

        $objRoom = new HotelRoomInformation((int) $idRoom);
        if (!Validate::isLoadedObject($objRoom)) {
            return array('success' => false, 'message' => $this->module->l('Invalid room.'));
        }

        if ((int) $objRoom->id_hotel !== (int) $objBooking->id_hotel) {
            return array('success' => false, 'message' => $this->module->l('Room belongs to a different hotel.'));
        }

        if ((int) $objRoom->id_product !== (int) $objBooking->id_product) {
            return array('success' => false, 'message' => $this->module->l('Cannot move to a different room type.'));
        }

        if ($objBooking->chechRoomBooked($idRoom, $objBooking->date_from, $objBooking->date_to)) {
            return array('success' => false, 'message' => $this->module->l('Target room is not available in the booking window.'));
        }

        if ($this->isRoomDisabled($idRoom, $objBooking->date_from, $objBooking->date_to)) {
            return array('success' => false, 'message' => $this->module->l('Target room is out of order in the booking window.'));
        }

        $before = $this->snapshot($objBooking);
        $oldRoom = (int) $objBooking->id_room;
        $oldFrom = $objBooking->date_from;
        $oldTo = $objBooking->date_to;
        $objBooking->id_room = (int) $idRoom;
        $objBooking->room_num = $objRoom->room_num;

        if ($objBooking->save()) {
            $this->syncCartBookingForBooking($objBooking, $oldRoom, $oldFrom, $oldTo);
            QlofdAuditLog::record('htl_booking', $objBooking->id, 'room_move', $before, $this->snapshot($objBooking), $this->idEmployee);
            QlofdUndo::push(array(
                'action' => 'moveBookingRoom',
                'id_htl_booking' => (int) $objBooking->id,
                'before' => $before,
            ));

            return array('success' => true, 'message' => '');
        }

        return array('success' => false, 'message' => $this->module->l('Unable to move booking.'));
    }

    /**
     * Swap the rooms of two bookings (same room type required).
     *
     * @param int $idHtlBookingFrom
     * @param int $idHtlBookingTo
     * @return array
     */
    public function swapBooking($idHtlBookingFrom, $idHtlBookingTo)
    {
        $objFrom = new HotelBookingDetail((int) $idHtlBookingFrom);
        $objTo = new HotelBookingDetail((int) $idHtlBookingTo);
        if (!Validate::isLoadedObject($objFrom) || !Validate::isLoadedObject($objTo)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        if ((int) $idHtlBookingFrom === (int) $idHtlBookingTo
            || (int) $objFrom->id_product !== (int) $objTo->id_product
            || (int) $objFrom->id_hotel !== (int) $objTo->id_hotel
        ) {
            return array('success' => false, 'message' => $this->module->l('These bookings cannot be swapped.'));
        }

        $beforeFrom = $this->snapshot($objFrom);
        $beforeTo = $this->snapshot($objTo);

        $objBookingDetail = new HotelBookingDetail();
        if ($objBookingDetail->swapBooking((int) $idHtlBookingFrom, (int) $idHtlBookingTo)) {
            QlofdAuditLog::record('htl_booking', (int) $idHtlBookingFrom, 'room_swap', $beforeFrom, $this->snapshot(new HotelBookingDetail((int) $idHtlBookingFrom)), $this->idEmployee);
            QlofdAuditLog::record('htl_booking', (int) $idHtlBookingTo, 'room_swap', $beforeTo, $this->snapshot(new HotelBookingDetail((int) $idHtlBookingTo)), $this->idEmployee);
            QlofdUndo::push(array(
                'action' => 'swapBooking',
                'id_htl_booking_from' => (int) $idHtlBookingFrom,
                'id_htl_booking_to' => (int) $idHtlBookingTo,
            ));

            return array('success' => true, 'message' => '');
        }

        return array('success' => false, 'message' => $this->module->l('Unable to swap bookings.'));
    }

    /**
     * Change the check-in/check-out dates of a booking. Recomputes totals
     * from the original per-night rate and syncs order detail + order totals.
     *
     * @param int $idHtlBooking
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d (exclusive)
     * @return array
     */
    public function updateBookingDates($idHtlBooking, $dateFrom, $dateTo)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        if (!Validate::isDate($dateFrom) || !Validate::isDate($dateTo)) {
            return array('success' => false, 'message' => $this->module->l('Invalid dates.'));
        }

        $newFrom = date('Y-m-d 00:00:00', strtotime($dateFrom));
        $newTo = date('Y-m-d 00:00:00', strtotime($dateTo));

        if ($objBooking->date_from == $newFrom && $objBooking->date_to == $newTo) {
            return array('success' => true, 'message' => '');
        }

        $origNights = max(1, HotelHelper::getNumberOfDays($objBooking->date_from, $objBooking->date_to));
        $newNights = HotelHelper::getNumberOfDays($newFrom, $newTo);
        if ($newNights < 1 || $newNights > 180) {
            return array('success' => false, 'message' => $this->module->l('Invalid stay duration.'));
        }

        if ($objBooking->chechRoomBooked($objBooking->id_room, $newFrom, $newTo)) {
            return array('success' => false, 'message' => $this->module->l('Room is booked for the new dates.'));
        }

        if ($this->isRoomDisabled($objBooking->id_room, $newFrom, $newTo)) {
            return array('success' => false, 'message' => $this->module->l('Room is out of order for the new dates.'));
        }

        $rateNightIncl = $objBooking->total_price_tax_incl / $origNights;
        $rateNightExcl = $objBooking->total_price_tax_excl / $origNights;
        $newTotalIncl = Tools::ps_round($rateNightIncl * $newNights, 6);
        $newTotalExcl = Tools::ps_round($rateNightExcl * $newNights, 6);

        $oldFrom = $objBooking->date_from;
        $oldTo = $objBooking->date_to;
        $oldRoom = (int) $objBooking->id_room;
        $oldTotalIncl = (float) $objBooking->total_price_tax_incl;
        $oldTotalExcl = (float) $objBooking->total_price_tax_excl;
        $before = $this->snapshot($objBooking);

        $objOrder = new Order((int) $objBooking->id_order);
        $orderRestore = Validate::isLoadedObject($objOrder) ? $this->captureOrderRestore($objBooking) : array();

        $objBooking->date_from = $newFrom;
        $objBooking->date_to = $newTo;
        $objBooking->planned_check_out = $newTo;
        $objBooking->total_price_tax_excl = $newTotalExcl;
        $objBooking->total_price_tax_incl = $newTotalIncl;

        if (!$objBooking->save()) {
            return array('success' => false, 'message' => $this->module->l('Unable to update booking dates.'));
        }

        $objOrderDetail = new OrderDetail((int) $objBooking->id_order_detail);
        if (Validate::isLoadedObject($objOrderDetail)) {
            $objOrderDetail->product_quantity = (int) $newNights;
            $objOrderDetail->unit_price_tax_excl = Tools::ps_round($rateNightExcl, 6);
            $objOrderDetail->unit_price_tax_incl = Tools::ps_round($rateNightIncl, 6);
            $objOrderDetail->total_price_tax_excl = $newTotalExcl;
            $objOrderDetail->total_price_tax_incl = $newTotalIncl;
            $objOrderDetail->update();
        }

        $this->syncCartBookingForBooking($objBooking, $oldRoom, $oldFrom, $oldTo);
        $this->rescaleDailyServiceQuantities($idHtlBooking, $origNights, $newNights);

        if (Validate::isLoadedObject($objOrder)) {
            $this->recomputeOrderTotals($objOrder);
        }

        QlofdBookingExtra::refreshPaymentStatus((int) $idHtlBooking);

        QlofdAuditLog::record('htl_booking', (int) $idHtlBooking, 'date_change', $before, $this->snapshot($objBooking), $this->idEmployee);
        QlofdUndo::push(array(
            'action' => 'updateBookingDates',
            'id_htl_booking' => (int) $idHtlBooking,
            'before' => $before,
            'order_restore' => $orderRestore,
        ));

        return array('success' => true, 'message' => '');
    }

    /**
     * Change booking status (check-in / check-out / cancel / no-show).
     *
     * @param int $idHtlBooking
     * @param string $action checkin|checkout|cancel|noshow
     * @param string $statusDate Y-m-d H:i:s
     * @return array
     */
    public function setBookingStatus($idHtlBooking, $action, $statusDate = '')
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        $before = $this->snapshot($objBooking);
        $statusDate = $statusDate ? date('Y-m-d H:i:s', strtotime($statusDate)) : date('Y-m-d H:i:s');
        $objExtra = QlofdBookingExtra::getForBooking((int) $idHtlBooking);

        switch ($action) {
            case 'checkin':
                if ($objBooking->id_status == HotelBookingDetail::STATUS_CHECKED_IN) {
                    return array('success' => true, 'message' => '');
                }
                $objBooking->id_status = HotelBookingDetail::STATUS_CHECKED_IN;
                $objBooking->check_in = $statusDate;
                $objBooking->check_out = '0000-00-00 00:00:00';
                break;

            case 'checkout':
                if ($objBooking->id_status == HotelBookingDetail::STATUS_CHECKED_OUT) {
                    return array('success' => true, 'message' => '');
                }
                if (!$objBooking->check_in || $objBooking->check_in == '0000-00-00 00:00:00') {
                    return array('success' => false, 'message' => $this->module->l('Room must be checked in before check-out.'));
                }
                if (strtotime($objBooking->check_in) > strtotime($statusDate)) {
                    return array('success' => false, 'message' => $this->module->l('Check-out cannot be before check-in.'));
                }
                $objBooking->id_status = HotelBookingDetail::STATUS_CHECKED_OUT;
                $objBooking->check_out = $statusDate;
                break;

            case 'cancel':
                if (!(int) $objBooking->is_cancelled) {
                    $orderRestore = Validate::isLoadedObject(new Order((int) $objBooking->id_order))
                        ? $this->captureOrderRestore($objBooking)
                        : array();
                    if (!$this->reconcileCancelledBooking($objBooking)) {
                        return array('success' => false, 'message' => $this->module->l('Unable to cancel booking.'));
                    }
                    $hasOrderRestore = true;
                }
                break;

            case 'noshow':
                $objExtra->is_no_show = 1;
                $objExtra->save();
                break;

            default:
                return array('success' => false, 'message' => $this->module->l('Unknown status action.'));
        }

        if ($action !== 'cancel' && $action !== 'noshow') {
            if (!$objBooking->save()) {
                return array('success' => false, 'message' => $this->module->l('Unable to update booking status.'));
            }
        }

        QlofdAuditLog::record('htl_booking', (int) $idHtlBooking, 'status_'.$action, $before, $this->snapshot($objBooking), $this->idEmployee);
        QlofdUndo::push(array(
            'action' => 'setBookingStatus',
            'id_htl_booking' => (int) $idHtlBooking,
            'before' => $before,
            'order_restore' => !empty($hasOrderRestore) ? $orderRestore : array(),
        ));

        return array('success' => true, 'message' => '');
    }

    /**
     * Toggle the lock flag preventing drag operations on a booking.
     *
     * @param int $idHtlBooking
     * @return array
     */
    public function toggleLockAssignment($idHtlBooking)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        $objExtra = QlofdBookingExtra::getForBooking((int) $idHtlBooking);
        $objExtra->is_locked_assignment = $objExtra->is_locked_assignment ? 0 : 1;
        if ($objExtra->save()) {
            QlofdAuditLog::record('htl_booking', (int) $idHtlBooking, 'assignment_lock', null, array('is_locked_assignment' => (int) $objExtra->is_locked_assignment), $this->idEmployee);
            return array('success' => true, 'is_locked_assignment' => (int) $objExtra->is_locked_assignment);
        }

        return array('success' => false, 'message' => $this->module->l('Unable to toggle lock.'));
    }

    /**
     * Update the booking note (comment).
     *
     * @param int $idHtlBooking
     * @param string $note
     * @return array
     */
    public function updateBookingNote($idHtlBooking, $note)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('success' => false, 'message' => $this->module->l('Invalid booking.'));
        }

        $before = $this->snapshot($objBooking);
        $objBooking->comment = pSQL($note, true);
        if ($objBooking->save()) {
            QlofdAuditLog::record('htl_booking', (int) $idHtlBooking, 'note_update', $before, $this->snapshot($objBooking), $this->idEmployee);
            return array('success' => true, 'message' => '');
        }

        return array('success' => false, 'message' => $this->module->l('Unable to update note.'));
    }

    /**
     * Walk-in booking: customer + cart + validateOrder flow. Returns the
     * newly created htl_booking_detail id on success.
     *
     * @param array $params
     * @return array success + id/errors
     */
    public function createBooking($params)
    {
        $context = Context::getContext();

        $idProduct = (int) $params['id_product'];
        $idHotel = (int) $params['id_hotel'];
        $idRoom = (int) $params['id_room'];
        $dateFrom = date('Y-m-d 00:00:00', strtotime($params['date_from']));
        $dateTo = date('Y-m-d 00:00:00', strtotime($params['date_to']));
        $adults = (int) $params['adults'];
        $children = (int) $params['children'];
        $comment = isset($params['comment']) ? pSQL($params['comment'], true) : '';

        $numNights = HotelHelper::getNumberOfDays($dateFrom, $dateTo);
        if ($numNights < 1) {
            return array('success' => false, 'message' => $this->module->l('Invalid stay duration.'));
        }

        $objRoom = new HotelRoomInformation($idRoom);
        if (!Validate::isLoadedObject($objRoom) || (int) $objRoom->id_product !== $idProduct
            || (int) $objRoom->id_hotel !== $idHotel
        ) {
            return array('success' => false, 'message' => $this->module->l('Invalid room.'));
        }

        $objBookingCheck = new HotelBookingDetail();
        if ($objBookingCheck->chechRoomBooked($idRoom, $dateFrom, $dateTo)) {
            return array('success' => false, 'message' => $this->module->l('Room is not available for the selected dates.'));
        }
        if ($this->isRoomDisabled($idRoom, $dateFrom, $dateTo)) {
            return array('success' => false, 'message' => $this->module->l('Room is out of order for the selected dates.'));
        }

        // 1. Resolve customer
        $idCustomer = (int) $params['id_customer'];
        if ($idCustomer) {
            $objCustomer = new Customer($idCustomer);
            if (!Validate::isLoadedObject($objCustomer)) {
                return array('success' => false, 'message' => $this->module->l('Guest not found.'));
            }
        } else {
            $email = trim($params['email']);
            $firstname = trim($params['firstname']);
            $lastname = trim($params['lastname']);
            if (!$email || !$firstname || !$lastname || !Validate::isEmail($email)) {
                return array('success' => false, 'message' => $this->module->l('Guest details are required.'));
            }

            if (Customer::customerExists($email)) {
                $objCustomer = new Customer(Customer::customerExists($email));
            } else {
                $objCustomer = new Customer();
                $objCustomer->id_shop = (int) $context->shop->id;
                $defaultGroup = (int) Configuration::get('PS_CUSTOMER_GROUP');
                $objCustomer->id_default_group = $defaultGroup ? $defaultGroup : 3;
                $objCustomer->firstname = Tools::ucfirst($firstname);
                $objCustomer->lastname = Tools::ucfirst($lastname);
                $objCustomer->email = $email;
                $objCustomer->passwd = Tools::encrypt(Tools::passwdGen(12));
                $objCustomer->active = 1;
                $objCustomer->is_guest = 0;
                if (!$objCustomer->add()) {
                    return array('success' => false, 'message' => $this->module->l('Unable to create guest.'));
                }
            }
        }
        $context->customer = $objCustomer;
        $idCustomer = (int) $objCustomer->id;

        // 2. Resolve address
        $addresses = $objCustomer->getAddresses((int) Configuration::get('PS_LANG_DEFAULT'));
        if (empty($addresses)) {
            $objAddress = new Address();
            $objAddress->id_customer = $idCustomer;
            $objAddress->alias = 'Front Desk';
            $objAddress->firstname = $objCustomer->firstname;
            $objAddress->lastname = $objCustomer->lastname;
            $objAddress->address1 = isset($params['address1']) ? pSQL($params['address1']) : 'Front Desk';
            $objAddress->postcode = isset($params['postcode']) ? pSQL($params['postcode']) : '00000';
            $objAddress->city = isset($params['city']) ? pSQL($params['city']) : 'N/A';
            $objAddress->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $objAddress->phone = isset($params['phone']) ? pSQL($params['phone']) : '';
            if (!$objAddress->add()) {
                return array('success' => false, 'message' => $this->module->l('Unable to create guest address.'));
            }
            $idAddress = (int) $objAddress->id;
        } else {
            $idAddress = (int) $addresses[0]['id_address'];
        }

        // 3. Prepare guest + cart context
        if (!isset($context->cookie->id_guest)) {
            Guest::setNewGuest($context->cookie);
        }

        $objCart = new Cart();
        $objCart->id_shop = (int) $context->shop->id;
        $objCart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $objCart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $objCart->id_customer = $idCustomer;
        $objCart->id_guest = (int) $context->cookie->id_guest;
        $objCart->id_address_delivery = $idAddress;
        $objCart->id_address_invoice = $idAddress;
        $objCart->secure_key = $objCustomer->secure_key;
        $objCart->setNoMultishipping();
        if (!$objCart->save()) {
            return array('success' => false, 'message' => $this->module->l('Unable to create cart.'));
        }

        $context->cart = $objCart;
        $context->currency = new Currency((int) $objCart->id_currency);
        $context->language = new Language((int) $objCart->id_lang);

        // 4. Add room to cart
        $isOccupancyMode = Configuration::get('PS_BACKOFFICE_ROOM_BOOKING_TYPE') == HotelBookingDetail::PS_ROOM_UNIT_SELECTION_TYPE_OCCUPANCY;
        if ($isOccupancyMode) {
            $occupancyInput = array(
                array(
                    'adults' => $adults,
                    'children' => $children,
                    'child_ages' => $children ? array_map('intval', (array) $params['child_ages']) : array(),
                )
            );
        } else {
            $occupancyInput = 1;
        }

        $objCartBookingData = new HotelCartBookingData();
        $idCartBooking = $objCartBookingData->addCartBookingData(
            $idProduct,
            $occupancyInput,
            $idHotel,
            $dateFrom,
            $dateTo,
            array(),
            array(),
            array(array('id_room' => $idRoom)),
            $objCart->id,
            $idRoom,
            HotelBookingDetail::ALLOTMENT_MANUAL,
            $comment
        );

        if (!$idCartBooking) {
            return array('success' => false, 'message' => $this->module->l('Unable to add room to cart.'));
        }

        // 5. Validate the order
        $orderTotal = (float) $objCart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);
        $deposit = isset($params['deposit']) && $params['deposit'] !== '' ? (float) $params['deposit'] : $orderTotal;
        $paymentMethod = isset($params['payment_method']) ? trim($params['payment_method']) : 'Pay at Hotel';
        $paymentType = isset($params['payment_type']) ? (int) $params['payment_type'] : OrderPayment::PAYMENT_TYPE_PAY_AT_HOTEL;

        if ($orderTotal <= 0) {
            $objPaymentModule = new FreeOrder();
            $idOrderState = (int) Configuration::get('PS_OS_PAYMENT_ACCEPTED');
            $amountPaid = 0.0;
        } else {
            $objPaymentModule = new BoOrder();
            $objPaymentModule->displayName = $paymentMethod;
            $objPaymentModule->payment_type = $paymentType;

            if ($deposit <= 0) {
                $idOrderState = (int) Configuration::get('PS_OS_AWAITING_PAYMENT');
                $amountPaid = 0.0;
            } elseif ($deposit >= $orderTotal) {
                $idOrderState = (int) Configuration::get('PS_OS_PAYMENT_ACCEPTED');
                $amountPaid = $orderTotal;
            } else {
                $idOrderState = (int) Configuration::get('PS_OS_PARTIAL_PAYMENT_ACCEPTED');
                $amountPaid = $deposit;
            }
        }

        $objEmployee = new Employee((int) $context->employee->id);
        $message = $this->module->l('Manual order -- Front Desk').': '.$objEmployee->firstname.' '.$objEmployee->lastname;
        $extraVars = (isset($params['reference']) && $params['reference'] !== '')
            ? array('transaction_id' => pSQL($params['reference']))
            : array();

        try {
            $objPaymentModule->validateOrder(
                $objCart->id,
                $idOrderState,
                $amountPaid,
                $objPaymentModule->displayName,
                $message,
                $extraVars,
                null,
                false,
                $objCart->secure_key,
                null,
                false
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog('QloFrontDesk createBooking error: '.$e->getMessage(), 3);
            return array('success' => false, 'message' => $this->module->l('Unable to create the order.'));
        }

        $idOrder = Order::getOrderByCartId($objCart->id);
        if (!$idOrder) {
            return array('success' => false, 'message' => $this->module->l('Order was not created.'));
        }

        $idHtlBooking = (int) Db::getInstance()->getValue(
            'SELECT `id` FROM `'._DB_PREFIX_.'htl_booking_detail`
             WHERE `id_order` = '.(int) $idOrder.'
             AND `id_room` = '.(int) $idRoom.'
             AND `date_from` = \''.pSQL($dateFrom).'\'
             AND `date_to` = \''.pSQL($dateTo).'\''
        );

        if (!$idHtlBooking) {
            return array('success' => false, 'message' => $this->module->l('Booking was not created.'));
        }

        $objExtra = QlofdBookingExtra::getForBooking($idHtlBooking);
        $objExtra->is_group_booking = isset($params['is_group_booking']) ? (int) $params['is_group_booking'] : 0;
        $objExtra->save();
        QlofdBookingExtra::refreshPaymentStatus($idHtlBooking);

        QlofdAuditLog::record('htl_booking', $idHtlBooking, 'create', null, $this->snapshot(new HotelBookingDetail($idHtlBooking)), $this->idEmployee);
        QlofdUndo::push(array(
            'action' => 'createBooking',
            'id_htl_booking' => $idHtlBooking,
        ));

        return array('success' => true, 'id' => $idHtlBooking, 'id_order' => (int) $idOrder);
    }

    /**
     * Reverse an undo item.
     *
     * @param array $undoItem
     * @return array
     */
    public function applyUndo($undoItem)
    {
        if (!is_array($undoItem) || !isset($undoItem['action'])) {
            return array('success' => false, 'message' => $this->module->l('Invalid undo entry.'));
        }

        switch ($undoItem['action']) {
            case 'moveBookingRoom':
            case 'updateBookingDates':
            case 'setBookingStatus':
                $objBooking = new HotelBookingDetail((int) $undoItem['id_htl_booking']);
                if (!Validate::isLoadedObject($objBooking) || empty($undoItem['before'])) {
                    return array('success' => false, 'message' => $this->module->l('Booking no longer available.'));
                }
                $this->restoreSnapshot($objBooking, $undoItem['before']);

                if (!empty($undoItem['order_restore'])) {
                    $this->applyOrderRestore($undoItem['order_restore']);
                }
                QlofdAuditLog::record('htl_booking', (int) $objBooking->id, 'undo', null, $this->snapshot($objBooking), $this->idEmployee);
                return array('success' => true, 'message' => '');

            case 'swapBooking':
                $objBookingDetail = new HotelBookingDetail();
                if (!$objBookingDetail->swapBooking(
                    (int) $undoItem['id_htl_booking_from'],
                    (int) $undoItem['id_htl_booking_to']
                )) {
                    return array('success' => false, 'message' => $this->module->l('Unable to undo swap.'));
                }
                return array('success' => true, 'message' => '');

            case 'createBooking':
                $objBooking = new HotelBookingDetail((int) $undoItem['id_htl_booking']);
                if (Validate::isLoadedObject($objBooking)) {
                    $idOrder = (int) $objBooking->id_order;
                    $idCart = (int) $objBooking->id_cart;

                    if (!$this->reconcileCancelledBooking($objBooking)) {
                        return array('success' => false, 'message' => $this->module->l('Unable to undo booking creation.'));
                    }

                    if ($idCart) {
                        Db::getInstance()->delete(
                            'htl_cart_booking_data',
                            '`id_cart` = '.(int) $idCart.($idOrder ? ' AND `id_order` = '.(int) $idOrder : ' AND `id_order` = 0')
                        );
                        Db::getInstance()->delete('cart', '`id_cart` = '.(int) $idCart);
                    }

                    if ($idOrder) {
                        $objOrder = new Order($idOrder);
                        if (Validate::isLoadedObject($objOrder)) {
                            $this->recomputeOrderTotals($objOrder);
                            $objHistory = new OrderHistory();
                            $objHistory->id_order = $idOrder;
                            $objHistory->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $objOrder);
                        }
                    }
                }
                QlofdAuditLog::record('htl_booking', (int) $undoItem['id_htl_booking'], 'undo_create', null, null, $this->idEmployee);
                return array('success' => true, 'message' => '');

            default:
                return array('success' => false, 'message' => $this->module->l('Cannot undo this action.'));
        }
    }

    /**
     * Field snapshot of a booking for audit + undo.
     *
     * @param HotelBookingDetail $objBooking
     * @return array
     */
    protected function snapshot($objBooking)
    {
        $fields = array(
            'id_room', 'room_num', 'date_from', 'date_to', 'planned_check_out',
            'id_status', 'check_in', 'check_out', 'comment',
            'total_price_tax_excl', 'total_price_tax_incl', 'total_paid_amount',
            'is_refunded', 'is_cancelled', 'is_back_order',
        );

        $data = array();
        foreach ($fields as $field) {
            if (isset($objBooking->{$field})) {
                $data[$field] = $objBooking->{$field};
            }
        }

        return $data;
    }

    /**
     * Apply a snapshot back onto a booking object.
     *
     * @param HotelBookingDetail $objBooking
     * @param array $snapshot
     * @return void
     */
    protected function restoreSnapshot($objBooking, $snapshot)
    {
        foreach ($snapshot as $field => $value) {
            if (property_exists($objBooking, $field)) {
                $objBooking->{$field} = $value;
            }
        }
        $objBooking->save();
    }

    /**
     * @param int $idRoom
     * @param string $dateFrom
     * @param string $dateTo
     * @return bool
     */
    protected function isRoomDisabled($idRoom, $dateFrom, $dateTo)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT `id` FROM `'._DB_PREFIX_.'htl_room_disable_dates`
             WHERE `id_room` = '.(int) $idRoom.'
             AND `date_from` < \''.pSQL(date('Y-m-d', strtotime($dateTo))).'\'
             AND `date_to` >= \''.pSQL(date('Y-m-d', strtotime($dateFrom))).'\''
        );
    }

    /**
     * Keep htl_cart_booking_data in sync with a booking after room/date changes.
     *
     * @param HotelBookingDetail $objBooking
     * @param int $origRoom
     * @param string $origFrom
     * @param string $origTo
     * @return bool
     */
    protected function syncCartBookingForBooking($objBooking, $origRoom = 0, $origFrom = null, $origTo = null)
    {
        $where = '`id_cart` = '.(int) $objBooking->id_cart
            .' AND `id_order` = '.(int) $objBooking->id_order;
        if ($origRoom) {
            $where .= ' AND `id_room` = '.(int) $origRoom;
        }
        if ($origFrom) {
            $where .= ' AND `date_from` = \''.pSQL($origFrom).'\'';
        }
        if ($origTo) {
            $where .= ' AND `date_to` = \''.pSQL($origTo).'\'';
        }

        return Db::getInstance()->update('htl_cart_booking_data', array(
            'id_room' => (int) $objBooking->id_room,
            'date_from' => pSQL($objBooking->date_from),
            'date_to' => pSQL($objBooking->date_to),
            'quantity' => (int) HotelHelper::getNumberOfDays($objBooking->date_from, $objBooking->date_to),
        ), $where);
    }

    /**
     * Rescale daily (per-night) service order-detail rows after a date change.
     * Nightly services carry their stay-length in the linked order_detail
     * (product_quantity equals the number of nights); fixed services keep qty 1.
     *
     * @param int $idHtlBooking
     * @param int $oldNights
     * @param int $newNights
     * @return void
     */
    protected function rescaleDailyServiceQuantities($idHtlBooking, $oldNights, $newNights)
    {
        if ((int) $oldNights === (int) $newNights) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_service_product_order_detail`, `id_order_detail`, `quantity`,
                    `total_price_tax_excl`, `total_price_tax_incl`
             FROM `'._DB_PREFIX_.'service_product_order_detail`
             WHERE `id_htl_booking_detail` = '.(int) $idHtlBooking
        );

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $idOrderDetail = (int) $row['id_order_detail'];
            if (!$idOrderDetail) {
                continue;
            }

            $objOrderDetail = new OrderDetail($idOrderDetail);
            if (!Validate::isLoadedObject($objOrderDetail) || (int) $objOrderDetail->product_quantity !== (int) $oldNights) {
                continue;
            }

            $newQty = (int) $newNights;
            $unitIncl = $oldNights > 0 ? (float) $objOrderDetail->total_price_tax_incl / (int) $oldNights : 0.0;
            $unitExcl = $oldNights > 0 ? (float) $objOrderDetail->total_price_tax_excl / (int) $oldNights : 0.0;

            $objOrderDetail->product_quantity = $newQty;
            $objOrderDetail->total_price_tax_excl = Tools::ps_round($unitExcl * $newQty, 6);
            $objOrderDetail->total_price_tax_incl = Tools::ps_round($unitIncl * $newQty, 6);
            $objOrderDetail->update();

            Db::getInstance()->update('service_product_order_detail', array(
                'total_price_tax_excl' => Tools::ps_round($unitExcl * $newQty, 6),
                'total_price_tax_incl' => Tools::ps_round($unitIncl * $newQty, 6),
            ), '`id_service_product_order_detail` = '.(int) $row['id_service_product_order_detail']);
        }
    }

    /**
     * Snapshot the order state (room + service order-detail lines + totals) so
     * date changes and cancellations can be reversed with undo.
     *
     * @param  HotelBookingDetail $objBooking
     * @return array
     */
    protected function captureOrderRestore($objBooking)
    {
        $restore = array();

        $objOrder = new Order((int) $objBooking->id_order);
        if (Validate::isLoadedObject($objOrder)) {
            $restore['id_order'] = (int) $objOrder->id;
            $restore['order_total_paid_tax_excl'] = (float) $objOrder->total_paid_tax_excl;
            $restore['order_total_paid_tax_incl'] = (float) $objOrder->total_paid_tax_incl;
        }

        $objOrderDetail = new OrderDetail((int) $objBooking->id_order_detail);
        if (Validate::isLoadedObject($objOrderDetail)) {
            $restore['details'][(int) $objOrderDetail->id] = array(
                'product_quantity' => (int) $objOrderDetail->product_quantity,
                'total_price_tax_excl' => (float) $objOrderDetail->total_price_tax_excl,
                'total_price_tax_incl' => (float) $objOrderDetail->total_price_tax_incl,
            );
        }

        $serviceRows = Db::getInstance()->executeS(
            'SELECT `id_service_product_order_detail`, `id_order_detail`, `quantity`,
                    `total_price_tax_excl`, `total_price_tax_incl`
             FROM `'._DB_PREFIX_.'service_product_order_detail`
             WHERE `id_htl_booking_detail` = '.(int) $objBooking->id
        );
        if ($serviceRows) {
            $restore['service_rows'] = array();
            foreach ($serviceRows as $row) {
                $restore['service_rows'][(int) $row['id_service_product_order_detail']] = array(
                    'id_order_detail' => (int) $row['id_order_detail'],
                    'quantity' => (int) $row['quantity'],
                    'total_price_tax_excl' => (float) $row['total_price_tax_excl'],
                    'total_price_tax_incl' => (float) $row['total_price_tax_incl'],
                );
            }
        }

        return $restore;
    }

    /**
     * Restore an order state snapshot produced by captureOrderRestore().
     *
     * @param array $orderRestore
     * @return void
     */
    protected function applyOrderRestore($orderRestore)
    {
        if (empty($orderRestore) || !is_array($orderRestore)) {
            return;
        }

        if (!empty($orderRestore['details'])) {
            foreach ($orderRestore['details'] as $idOrderDetail => $data) {
                $objOrderDetail = new OrderDetail((int) $idOrderDetail);
                if (Validate::isLoadedObject($objOrderDetail)) {
                    $objOrderDetail->product_quantity = (int) $data['product_quantity'];
                    $objOrderDetail->total_price_tax_excl = (float) $data['total_price_tax_excl'];
                    $objOrderDetail->total_price_tax_incl = (float) $data['total_price_tax_incl'];
                    $objOrderDetail->update();
                }
            }
        }

        if (!empty($orderRestore['service_rows'])) {
            foreach ($orderRestore['service_rows'] as $idService => $data) {
                Db::getInstance()->update('service_product_order_detail', array(
                    'quantity' => (int) $data['quantity'],
                    'total_price_tax_excl' => (float) $data['total_price_tax_excl'],
                    'total_price_tax_incl' => (float) $data['total_price_tax_incl'],
                ), '`id_service_product_order_detail` = ' . (int) $idService);

                if (!empty($data['id_order_detail'])) {
                    Db::getInstance()->update('order_detail', array(
                        'product_quantity' => (int) $data['quantity'],
                        'total_price_tax_excl' => (float) $data['total_price_tax_excl'],
                        'total_price_tax_incl' => (float) $data['total_price_tax_incl'],
                    ), '`id_order_detail` = ' . (int) $data['id_order_detail']);
                }
            }
        }

        if (!empty($orderRestore['id_order'])) {
            $objOrder = new Order((int) $orderRestore['id_order']);
            if (Validate::isLoadedObject($objOrder)) {
                $objOrder->total_paid_tax_excl = (float) $orderRestore['order_total_paid_tax_excl'];
                $objOrder->total_paid_tax_incl = (float) $orderRestore['order_total_paid_tax_incl'];
                $objOrder->update();
            }
        }
    }

    /**
     * Recompute order totals from its order_detail lines after a date change.
     *
     * @param Order $objOrder
     * @return void
     */
    protected function recomputeOrderTotals($objOrder)
    {
        $sum = Db::getInstance()->getRow(
            'SELECT SUM(`total_price_tax_excl`) AS `t_excl`, SUM(`total_price_tax_incl`) AS `t_incl`
             FROM `' . _DB_PREFIX_ . 'order_detail`
             WHERE `id_order` = ' . (int) $objOrder->id
        );

        if ($sum && $sum['t_excl'] !== null) {
            $objOrder->total_paid_tax_excl = (float) $sum['t_excl'];
            $objOrder->total_paid_tax_incl = (float) $sum['t_incl'];
            $objOrder->update();
        }
    }

    /**
     * Zero the order lines of a cancelled booking and free its room in one
     * consistent step (matches core behavior where is_refunded frees the room).
     *
     * @param HotelBookingDetail $objBooking
     * @return bool
     */
    protected function reconcileCancelledBooking($objBooking)
    {
        // room order detail line
        $objOrderDetail = new OrderDetail((int) $objBooking->id_order_detail);
        if (Validate::isLoadedObject($objOrderDetail)) {
            $objOrderDetail->product_quantity = 0;
            $objOrderDetail->total_price_tax_excl = 0.0;
            $objOrderDetail->total_price_tax_incl = 0.0;
            $objOrderDetail->update();
        }

        // demands and daily service rows linked to this booking
        Db::getInstance()->update('htl_booking_demands', array(
            'total_price_tax_excl' => 0.0,
            'total_price_tax_incl' => 0.0,
        ), '`id_htl_booking` = ' . (int) $objBooking->id);

        $serviceRows = Db::getInstance()->executeS(
            'SELECT `id_service_product_order_detail`, `id_order_detail`
             FROM `' . _DB_PREFIX_ . 'service_product_order_detail`
             WHERE `id_htl_booking_detail` = ' . (int) $objBooking->id
        );
        if ($serviceRows) {
            foreach ($serviceRows as $row) {
                Db::getInstance()->update('service_product_order_detail', array(
                    'quantity' => 0,
                    'total_price_tax_excl' => 0.0,
                    'total_price_tax_incl' => 0.0,
                ), '`id_service_product_order_detail` = ' . (int) $row['id_service_product_order_detail']);

                if ((int) $row['id_order_detail']) {
                    Db::getInstance()->update('order_detail', array(
                        'product_quantity' => 0,
                        'total_price_tax_excl' => 0.0,
                        'total_price_tax_incl' => 0.0,
                    ), '`id_order_detail` = ' . (int) $row['id_order_detail']);
                }
            }
        }

        $objBooking->is_cancelled = 1;
        $objBooking->is_refunded = 1;
        $objBooking->id_status = HotelBookingDetail::STATUS_ALLOTED;
        $result = $objBooking->save();

        $objOrder = new Order((int) $objBooking->id_order);
        if ($result && Validate::isLoadedObject($objOrder)) {
            $this->recomputeOrderTotals($objOrder);
        }

        return $result;
    }
}