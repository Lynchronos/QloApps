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

class QlofdFolioPayment extends ObjectModel
{
    /** @var int */
    public $id;

    /** @var int */
    public $id_htl_booking;

    /** @var int */
    public $id_order_payment;

    /** @var float */
    public $amount;

    /** @var string */
    public $payment_method;

    /** @var string */
    public $reference;

    /** @var int */
    public $id_currency;

    /** @var int */
    public $id_employee;

    /** @var string */
    public $date_add;

    public static $definition = array(
        'table' => 'qlofd_folio_payment',
        'primary' => 'id',
        'fields' => array(
            'id_htl_booking' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_order_payment' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'amount' => array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
            'payment_method' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'reference' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'id_currency' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param int $idHtlBooking
     * @param int $idOrderPayment
     * @return QlofdFolioPayment|false
     */
    public static function getForOrderPayment($idHtlBooking, $idOrderPayment)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id` FROM `'._DB_PREFIX_.'qlofd_folio_payment`
             WHERE `id_htl_booking` = '.(int) $idHtlBooking.'
             AND `id_order_payment` = '.(int) $idOrderPayment
        );

        if ($id) {
            return new QlofdFolioPayment($id);
        }

        return false;
    }
}