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

class QlofdFolioLine extends ObjectModel
{
    const TYPE_CHARGE = 'charge';
    const TYPE_CREDIT = 'credit';

    /** @var int */
    public $id;

    /** @var int */
    public $id_htl_booking;

    /** @var string */
    public $label;

    /** @var int */
    public $qty;

    /** @var float */
    public $unit_price_tax_excl;

    /** @var float */
    public $unit_price_tax_incl;

    /** @var string */
    public $type;

    /** @var int */
    public $id_employee;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = array(
        'table' => 'qlofd_folio_line',
        'primary' => 'id',
        'fields' => array(
            'id_htl_booking' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'label' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 255),
            'qty' => array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true),
            'unit_price_tax_excl' => array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
            'unit_price_tax_incl' => array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
            'type' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param int $idHtlBooking
     * @return array
     */
    public static function getForBooking($idHtlBooking)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `'._DB_PREFIX_.'qlofd_folio_line`
             WHERE `id_htl_booking` = '.(int) $idHtlBooking.'
             ORDER BY `id` ASC'
        );
    }

    /**
     * @param int $idHtlBooking
     * @return array taxes excl / incl
     */
    public static function getTotalsForBooking($idHtlBooking)
    {
        $row = Db::getInstance()->getRow(
            'SELECT
                SUM(IF(`type` = \'charge\', `unit_price_tax_excl`, -`unit_price_tax_excl`) * `qty`) AS `total_tax_excl`,
                SUM(IF(`type` = \'charge\', `unit_price_tax_incl`, -`unit_price_tax_incl`) * `qty`) AS `total_tax_incl`
             FROM `'._DB_PREFIX_.'qlofd_folio_line`
             WHERE `id_htl_booking` = '.(int) $idHtlBooking
        );

        return array(
            'total_tax_excl' => (float) $row['total_tax_excl'],
            'total_tax_incl' => (float) $row['total_tax_incl'],
        );
    }
}