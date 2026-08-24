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

class QlofdGrid
{
    /** @var int */
    protected $idHotel;

    /** @var int */
    protected $idRoomType;

    /** @var string */
    protected $dateFrom;

    /** @var string */
    protected $dateTo;

    /** @var int */
    protected $idLang;

    /** @var int */
    protected $windowDays;

    /**
     * @param int $idHotel
     * @param int $idRoomType
     * @param string $dateFrom Y-m-d
     */
    public function __construct($idHotel = 0, $idRoomType = 0, $dateFrom = '')
    {
        $this->idHotel = (int) $idHotel;
        $this->idRoomType = (int) $idRoomType;
        $this->idLang = (int) Context::getContext()->language->id;
        $this->windowDays = (int) Configuration::get('QLOFD_WINDOW_DAYS');

        if ($this->windowDays < 1) {
            $this->windowDays = 14;
        }

        $requestedFrom = Validate::isDate($dateFrom) ? $dateFrom : date('Y-m-d');
        $this->dateFrom = $requestedFrom;
        $this->dateTo = date('Y-m-d', strtotime($requestedFrom.' +'.($this->windowDays - 1).' day'));
    }

    /**
     * Resolve default hotel from profile access.
     *
     * @param int $idProfile
     * @param int $requestedHotel
     * @return int
     */
    public function resolveHotel($idProfile, $requestedHotel = 0)
    {
        $accessed = HotelBranchInformation::getProfileAccessedHotels((int) $idProfile, 2, 1);
        if ($requestedHotel && in_array((int) $requestedHotel, $accessed)) {
            return (int) $requestedHotel;
        }

        return (int) reset($accessed);
    }

    /**
     * Hotels accessible to the profile (with names).
     *
     * @param int $idProfile
     * @return array
     */
    public function getHotels($idProfile)
    {
        $hotels = HotelBranchInformation::getProfileAccessedHotels((int) $idProfile, 2, 0, $this->idLang);
        return $hotels ? $hotels : array();
    }

    /**
     * Room types available for the selected hotel.
     *
     * @return array
     */
    public function getRoomTypes()
    {
        if (!$this->idHotel) {
            return array();
        }

        $objRoomType = new HotelRoomType();
        $roomTypes = $objRoomType->getRoomTypeByHotelId($this->idHotel, $this->idLang, 1);

        return $roomTypes ? $roomTypes : array();
    }

    /**
     * All rooms of the selected hotel (active + temporarily inactive).
     *
     * @return array
     */
    public function getRooms()
    {
        if (!$this->idHotel) {
            return array();
        }

        $sql = 'SELECT `hri`.`id`, `hri`.`room_num`, `hri`.`floor`, `hri`.`id_hotel`,
                       `hri`.`id_product`, `hri`.`id_status`, `pl`.`name` AS `room_type_name`,
                       `hrt`.`adults`, `hrt`.`children`, `hrt`.`max_guests`
                FROM `'._DB_PREFIX_.'htl_room_information` `hri`
                INNER JOIN `'._DB_PREFIX_.'htl_room_type` `hrt` ON (`hrt`.`id_product` = `hri`.`id_product`)
                INNER JOIN `'._DB_PREFIX_.'product_lang` `pl`
                    ON (`pl`.`id_product` = `hri`.`id_product` AND `pl`.`id_lang` = '.(int) $this->idLang.')
                WHERE `hri`.`id_hotel` = '.(int) $this->idHotel.'
                AND `hri`.`id_status` IN (1, 3)';

        if ($this->idRoomType) {
            $sql .= ' AND `hri`.`id_product` = '.(int) $this->idRoomType;
        }

        $sql .= ' ORDER BY `pl`.`name` ASC, `hri`.`room_num` ASC';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Bookings overlapping the window for the selected hotel.
     *
     * @return array
     */
    public function getBookings()
    {
        if (!$this->idHotel) {
            return array();
        }

        $windowStart = $this->dateFrom.' 00:00:00';
        $windowEnd = date('Y-m-d', strtotime($this->dateTo.' +1 day')).' 00:00:00';

        $sql = 'SELECT `bd`.*, `o`.`id_currency`, `o`.`total_paid`, `o`.`total_paid_tax_incl`,
                       `cu`.`firstname`, `cu`.`lastname`, `cu`.`email`,
                       `qbe`.`is_locked_assignment`, `qbe`.`is_group_booking`, `qbe`.`payment_status`,
                       `qbe`.`is_no_show`, `qbe`.`no_show_risk_label`, `qbe`.`no_show_risk_score`
                FROM `'._DB_PREFIX_.'htl_booking_detail` `bd`
                LEFT JOIN `'._DB_PREFIX_.'orders` `o` ON (`o`.`id_order` = `bd`.`id_order`)
                LEFT JOIN `'._DB_PREFIX_.'customer` `cu` ON (`cu`.`id_customer` = `bd`.`id_customer`)
                LEFT JOIN `'._DB_PREFIX_.'qlofd_booking_extra` `qbe` ON (`qbe`.`id_htl_booking` = `bd`.`id`)
                WHERE `bd`.`id_hotel` = '.(int) $this->idHotel.'
                AND `bd`.`is_cancelled` = 0
                AND `bd`.`date_from` < \''.pSQL($windowEnd).'\'
                AND `bd`.`date_to` > \''.pSQL($windowStart).'\'';

        if ($this->idRoomType) {
            $sql .= ' AND `bd`.`id_product` = '.(int) $this->idRoomType;
        }

        $sql .= ' ORDER BY `bd`.`date_from` ASC';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * OOO ranges (htl_room_disable_dates) overlapping the window.
     *
     * @return array
     */
    public function getOoo()
    {
        if (!$this->idHotel) {
            return array();
        }

        $windowStart = $this->dateFrom;
        $windowEnd = $this->dateTo;

        $sql = 'SELECT `hrdd`.`id`, `hrdd`.`id_room`, `hrdd`.`date_from`, `hrdd`.`date_to`, `hrdd`.`reason`
                FROM `'._DB_PREFIX_.'htl_room_disable_dates` `hrdd`
                INNER JOIN `'._DB_PREFIX_.'htl_room_information` `hri` ON (`hri`.`id` = `hrdd`.`id_room`)
                WHERE `hri`.`id_hotel` = '.(int) $this->idHotel.'
                AND `hrdd`.`date_from` < \''.pSQL($this->dateTo).'\'
                AND `hrdd`.`date_to` >= \''.pSQL($this->dateFrom).'\'';

        if ($this->idRoomType) {
            $sql .= ' AND `hri`.`id_product` = '.(int) $this->idRoomType;
        }

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Assemble the full grid payload for the controller/template.
     *
     * @param int $idProfile
     * @return array
     */
    public function getGridData($idProfile)
    {
        $this->idHotel = $this->resolveHotel((int) $idProfile, $this->idHotel);

        $dates = array();
        for ($i = 0; $i < $this->windowDays; $i++) {
            $dates[] = date('Y-m-d', strtotime($this->dateFrom.' +'.$i.' day'));
        }

        $rooms = $this->getRooms();

        $housekeeping = QlofdHousekeepingLog::getCurrentStatusForRooms(
            $rooms ? array_column($rooms, 'id') : array()
        );

        foreach ($rooms as &$room) {
            $room['is_temp_inactive'] = ((int) $room['id_status'] === 3);
            $room['housekeeping'] = isset($housekeeping[$room['id']]) ? $housekeeping[$room['id']] : null;
        }
        unset($room);

        $bookingRows = $this->getBookings();
        $bookingBars = array();
        $orderGroupCounts = array();
        if ($bookingRows) {
            foreach ($bookingRows as $row) {
                $idOrder = (int) $row['id_order'];
                $orderGroupCounts[$idOrder] = isset($orderGroupCounts[$idOrder])
                    ? $orderGroupCounts[$idOrder] + 1
                    : 1;
            }
            foreach ($bookingRows as $row) {
                $guestName = trim($row['firstname'].' '.$row['lastname']);
                if ($guestName === '') {
                    $guestName = $row['room_num'];
                }

                $bookingBars[] = array(
                    'id' => (int) $row['id'],
                    'id_htl_booking' => (int) $row['id'],
                    'id_room' => (int) $row['id_room'],
                    'id_product' => (int) $row['id_product'],
                    'id_order' => (int) $row['id_order'],
                    'date_from' => date('Y-m-d', strtotime($row['date_from'])),
                    'date_to' => date('Y-m-d', strtotime($row['date_to'])),
                    'check_in' => $row['check_in'],
                    'check_out' => $row['check_out'],
                    'status' => (int) $row['id_status'],
                    'customer' => $guestName,
                    'customer_email' => $row['email'],
                    'room_num' => $row['room_num'],
                    'room_type_name' => $row['room_type_name'],
                    'adults' => (int) $row['adults'],
                    'children' => (int) $row['children'],
                    'total_paid' => (float) $row['total_paid'],
                    'total_paid_tax_incl' => (float) $row['total_paid_tax_incl'],
                    'total_price_tax_incl' => (float) $row['total_price_tax_incl'],
                    'id_currency' => (int) $row['id_currency'],
                    'is_locked_assignment' => (int) $row['is_locked_assignment'],
                    'is_group_booking' => (int) $row['is_group_booking'],
                    'group_overlap' => $orderGroupCounts[$idOrder],
                    'payment_status' => $row['payment_status'],
                    'no_show_label' => $row['no_show_risk_label'],
                    'is_no_show' => (int) $row['is_no_show'],
                    'comment' => $row['comment'],
                );
            }
        }

        return array(
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'dates' => $dates,
            'rooms' => $rooms,
            'room_types' => $this->getRoomTypes(),
            'booking_bars' => $bookingBars,
            'ooo' => $this->getOoo(),
            'groupIdThreshold' => (int) Configuration::get('QLOFD_GROUP_BOOKING_THRESHOLD'),
        );
    }
}