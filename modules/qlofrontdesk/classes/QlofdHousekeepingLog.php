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

class QlofdHousekeepingLog extends ObjectModel
{
    const STATUS_CLEAN = 'clean';
    const STATUS_DIRTY = 'dirty';
    const STATUS_INSPECTED = 'inspected';
    const STATUS_DO_NOT_DISTURB = 'do_not_disturb';

    /** @var int */
    public $id;

    /** @var int */
    public $id_room;

    /** @var string */
    public $status;

    /** @var string */
    public $note;

    /** @var int */
    public $id_employee;

    /** @var string */
    public $date_add;

    public static $definition = array(
        'table' => 'qlofd_housekeeping_log',
        'primary' => 'id',
        'fields' => array(
            'id_room' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true),
            'note' => array('type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'),
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Get map room_id => current status for a set of rooms.
     *
     * @param array $idRooms
     * @return array
     */
    public static function getCurrentStatusForRooms($idRooms)
    {
        $result = array();
        $idRooms = array_map('intval', (array) $idRooms);
        $idRooms = array_filter($idRooms);

        if (empty($idRooms)) {
            return $result;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `hk`.`id_room`, `hk`.`status`
             FROM `'._DB_PREFIX_.'qlofd_housekeeping_log` `hk`
             INNER JOIN (
                 SELECT `id_room`, MAX(`id`) AS `max_id`
                 FROM `'._DB_PREFIX_.'qlofd_housekeeping_log`
                 WHERE `id_room` IN ('.implode(',', $idRooms).')
                 GROUP BY `id_room`
             ) `latest` ON `hk`.`id` = `latest`.`max_id`'
        );

        if ($rows) {
            foreach ($rows as $row) {
                $result[(int) $row['id_room']] = $row['status'];
            }
        }

        return $result;
    }

    /**
     * Get status of a single room.
     *
     * @param int $idRoom
     * @return string|null
     */
    public static function getStatusForRoom($idRoom)
    {
        $statuses = self::getCurrentStatusForRooms(array((int) $idRoom));

        return isset($statuses[(int) $idRoom]) ? $statuses[(int) $idRoom] : null;
    }

    /**
     * Log a status change for a room.
     *
     * @param int $idRoom
     * @param string $status
     * @param string $note
     * @param int $idEmployee
     * @return bool
     */
    public static function logStatus($idRoom, $status, $note = '', $idEmployee = 0)
    {
        $allowed = array(
            self::STATUS_CLEAN,
            self::STATUS_DIRTY,
            self::STATUS_INSPECTED,
            self::STATUS_DO_NOT_DISTURB,
        );

        if (!in_array($status, $allowed)) {
            return false;
        }

        $objLog = new QlofdHousekeepingLog();
        $objLog->id_room = (int) $idRoom;
        $objLog->status = $status;
        $objLog->note = pSQL($note, true);
        $objLog->id_employee = (int) $idEmployee;

        return $objLog->save();
    }

    /**
     * Mark rooms of checked-out bookings dirty. Runs daily via cron.
     *
     * @return int number of rooms updated
     */
    public function markCheckedOutRoomsDirty()
    {
        $sql = 'SELECT DISTINCT `bd`.`id_room`
                FROM `'._DB_PREFIX_.'htl_booking_detail` `bd`
                WHERE `bd`.`id_status` = '.(int) HotelBookingDetail::STATUS_CHECKED_OUT.'
                AND `bd`.`date_to` <= \''.pSQL(date('Y-m-d')).' 23:59:59\'';
        $rooms = Db::getInstance()->executeS($sql);

        $count = 0;
        if ($rooms) {
            foreach ($rooms as $room) {
                self::logStatus((int) $room['id_room'], self::STATUS_DIRTY, 'Auto-flagged after check-out (cron)');
                $count++;
            }
        }

        return $count;
    }
}