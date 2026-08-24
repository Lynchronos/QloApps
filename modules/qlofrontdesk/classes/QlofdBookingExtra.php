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

class QlofdBookingExtra extends ObjectModel
{
    /** @var int */
    public $id;

    /** @var int */
    public $id_htl_booking;

    /** @var bool */
    public $is_locked_assignment;

    /** @var bool */
    public $is_group_booking;

    /** @var string */
    public $payment_status;

    /** @var bool */
    public $is_no_show;

    /** @var float */
    public $no_show_risk_score;

    /** @var string */
    public $no_show_risk_label;

    /** @var string */
    public $no_show_risk_updated_at;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = array(
        'table' => 'qlofd_booking_extra',
        'primary' => 'id',
        'fields' => array(
            'id_htl_booking' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'is_locked_assignment' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'is_group_booking' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'payment_status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'is_no_show' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'no_show_risk_score' => array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
            'no_show_risk_label' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'no_show_risk_updated_at' => array('type' => self::TYPE_DATE),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Get the extension row for a booking, creating it on demand.
     *
     * @param int $idHtlBooking
     * @return self
     */
    public static function getForBooking($idHtlBooking)
    {
        $idHtlBooking = (int) $idHtlBooking;
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id` FROM `'._DB_PREFIX_.'qlofd_booking_extra`
             WHERE `id_htl_booking` = '.(int) $idHtlBooking
        );

        if ($id) {
            return new QlofdBookingExtra($id);
        }

        $objExtra = new QlofdBookingExtra();
        $objExtra->id_htl_booking = (int) $idHtlBooking;
        $objExtra->is_locked_assignment = 0;
        $objExtra->is_group_booking = 0;
        $objExtra->payment_status = 'pending';
        $objExtra->is_no_show = 0;
        $objExtra->save();

        return $objExtra;
    }

    /**
     * Update payment status from actual order payments.
     *
     * @param int $idHtlBooking
     * @return void
     */
    public static function refreshPaymentStatus($idHtlBooking)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return;
        }

        $objOrder = new Order((int) $objBooking->id_order);
        if (!Validate::isLoadedObject($objOrder)) {
            return;
        }

        // Base payment status on real order_payment rows: QloApps may keep
        // orders.total_paid at the full order value even for unpaid BO orders.
        $paidAmount = (float) Db::getInstance()->getValue(
            'SELECT SUM(`amount`) FROM `'._DB_PREFIX_.'order_payment`
             WHERE `order_reference` = \''.pSQL($objOrder->reference).'\''
        );

        $orderTotal = (float) $objOrder->total_paid_tax_incl;

        $status = 'pending';
        if ($orderTotal > 0 && $paidAmount >= $orderTotal) {
            $status = 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partial';
        }

        $objExtra = QlofdBookingExtra::getForBooking((int) $idHtlBooking);
        if ($objExtra->payment_status != $status) {
            $objExtra->payment_status = $status;
            $objExtra->save();
        }
    }
}