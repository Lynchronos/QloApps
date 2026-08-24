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
 * Greedy room-assignment suggestion. Prefers candidate rooms that are
 * already occupied adjacent to the requested window (keeps free blocks
 * contiguous) with a small deterministic bias toward lower room numbers.
 */
class QlofdRoomAssignment
{
    const NEIGHBOUR_WINDOW_DAYS = 7;

    /**
     * Suggest the best room for a booking window.
     *
     * @param int $idHotel
     * @param int $idProduct room type product id
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d (exclusive)
     * @param int $excludeBookingId booking to ignore (room moves)
     * @return array ['id_room' => int, 'room_num' => string, 'score' => int]
     */
    public static function suggest($idHotel, $idProduct, $dateFrom, $dateTo, $excludeBookingId = 0)
    {
        $idHotel = (int) $idHotel;
        $idProduct = (int) $idProduct;
        $excludeBookingId = (int) $excludeBookingId;

        if (!Validate::isDate($dateFrom) || !Validate::isDate($dateTo)
            || strtotime($dateTo) <= strtotime($dateFrom)
        ) {
            return array();
        }

        $candidates = self::getAvailableRooms($idHotel, $idProduct, $dateFrom, $dateTo, $excludeBookingId);
        if (empty($candidates)) {
            return array();
        }

        $idRooms = array_column($candidates, 'id_room');
        $occupancyBefore = self::getRoomOccupancyMap($idRooms, $dateFrom, '-', self::NEIGHBOUR_WINDOW_DAYS, $excludeBookingId);
        $occupancyAfter = self::getRoomOccupancyMap($idRooms, $dateTo, '+', self::NEIGHBOUR_WINDOW_DAYS, $excludeBookingId);

        $best = null;
        foreach ($candidates as $candidate) {
            $score = 0;
            if (!empty($occupancyBefore[$candidate['id_room']])) {
                $score++;
            }
            if (!empty($occupancyAfter[$candidate['id_room']])) {
                $score++;
            }

            $candidate['score'] = $score;
            if (null === $best
                || $score > $best['score']
                || ($score === $best['score'] && $candidate['room_num'] < $best['room_num'])
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Active rooms of the room type that are free across the whole window.
     *
     * @param int $idHotel
     * @param int $idProduct
     * @param string $dateFrom
     * @param string $dateTo
     * @param int $excludeBookingId
     * @return array
     */
    protected static function getAvailableRooms($idHotel, $idProduct, $dateFrom, $dateTo, $excludeBookingId)
    {
        $idLang = (int) Context::getContext()->language->id;

        $sql = 'SELECT `hri`.`id` AS `id_room`, `hri`.`room_num`
                FROM `'._DB_PREFIX_.'htl_room_information` `hri`
                INNER JOIN `'._DB_PREFIX_.'product_lang` `pl`
                    ON (`pl`.`id_product` = `hri`.`id_product` AND `pl`.`id_lang` = '.(int) $idLang.')
                WHERE `hri`.`id_hotel` = '.(int) $idHotel.'
                AND `hri`.`id_product` = '.(int) $idProduct.'
                AND `hri`.`id_status` = 1
                AND `hri`.`id` NOT IN (
                    SELECT DISTINCT `bd`.`id_room`
                    FROM `'._DB_PREFIX_.'htl_booking_detail` `bd`
                    WHERE `bd`.`id_room` <> 0
                    AND `bd`.`is_cancelled` = 0
                    '.($excludeBookingId ? 'AND `bd`.`id` <> '.(int) $excludeBookingId : '').'
                    AND `bd`.`date_from` < \''.pSQL(date('Y-m-d', strtotime($dateTo))).'\'
                    AND `bd`.`date_to` > \''.pSQL(date('Y-m-d', strtotime($dateFrom))).'\'
                )
                AND `hri`.`id` NOT IN (
                    SELECT DISTINCT `hrdd`.`id_room`
                    FROM `'._DB_PREFIX_.'htl_room_disable_dates` `hrdd`
                    WHERE `hrdd`.`date_from` < \''.pSQL(date('Y-m-d', strtotime($dateTo))).'\'
                    AND `hrdd`.`date_to` >= \''.pSQL(date('Y-m-d', strtotime($dateFrom))).'\'
                )';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Map room_id => has_booking for a window adjacent to a pivot date.
     *
     * @param array $idRooms
     * @param string $pivotDate Y-m-d
     * @param string $direction '-' before / '+' after
     * @param int $days
     * @param int $excludeBookingId
     * @return array
     */
    protected static function getRoomOccupancyMap($idRooms, $pivotDate, $direction, $days, $excludeBookingId)
    {
        $idRooms = array_map('intval', (array) $idRooms);
        $idRooms = array_filter($idRooms);
        if (empty($idRooms)) {
            return array();
        }

        if ($direction === '-') {
            $rangeEnd = $pivotDate;
            $rangeStart = date('Y-m-d', strtotime($pivotDate.' -'.$days.' day'));
        } else {
            $rangeStart = $pivotDate;
            $rangeEnd = date('Y-m-d', strtotime($pivotDate.' +'.$days.' day'));
        }

        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `bd`.`id_room`
             FROM `'._DB_PREFIX_.'htl_booking_detail` `bd`
             WHERE `bd`.`id_room` IN ('.implode(',', $idRooms).')
             AND `bd`.`is_cancelled` = 0
             '.($excludeBookingId ? 'AND `bd`.`id` <> '.(int) $excludeBookingId : '').'
             AND `bd`.`date_from` < \''.pSQL($rangeEnd).'\'
             AND `bd`.`date_to` > \''.pSQL($rangeStart).'\''
        );

        $map = array();
        if ($rows) {
            foreach ($rows as $row) {
                $map[(int) $row['id_room']] = true;
            }
        }

        return $map;
    }
}