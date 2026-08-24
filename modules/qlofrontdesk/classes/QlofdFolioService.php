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
 * Folio calculation service: combines order charges, folio lines and
 * payments into a running balance for a booking.
 */
class QlofdFolioService
{
    /** @var int */
    protected $idHtlBooking;

    /**
     * @param int $idHtlBooking
     */
    public function __construct($idHtlBooking)
    {
        $this->idHtlBooking = (int) $idHtlBooking;
    }

    /**
     * Build the full folio payload: lines, payments, totals, balance.
     *
     * @return array
     */
    public function getFolioData()
    {
        $objBooking = new HotelBookingDetail($this->idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array();
        }

        $objOrder = new Order((int) $objBooking->id_order);
        $currency = Validate::isLoadedObject($objOrder) ? new Currency($objOrder->id_currency) : null;
        $idCurrency = Validate::isLoadedObject($objOrder) ? (int) $objOrder->id_currency : 0;

        $objCustomer = new Customer((int) $objBooking->id_customer);
        $customerName = Validate::isLoadedObject($objCustomer)
            ? trim($objCustomer->firstname.' '.$objCustomer->lastname)
            : '';

        $roomChargeTaxIncl = (float) $objBooking->total_price_tax_incl;
        $roomChargeTaxExcl = (float) $objBooking->total_price_tax_excl;

        $objExtra = QlofdBookingExtra::getForBooking($this->idHtlBooking);
        $paymentStatus = $objExtra->payment_status;

        $demandsTaxIncl = 0.0;
        $demandsTaxExcl = 0.0;
        $demandRows = Db::getInstance()->executeS(
            'SELECT `name`, `total_price_tax_excl`, `total_price_tax_incl`
             FROM `'._DB_PREFIX_.'htl_booking_demands`
             WHERE `id_htl_booking` = '.(int) $this->idHtlBooking.'
             ORDER BY `id_booking_demand` ASC'
        );
        $demandItems = array();
        if ($demandRows) {
            foreach ($demandRows as $demand) {
                $demandItems[] = array(
                    'label' => $demand['name'],
                    'qty' => 1,
                    'unit_price_tax_excl' => (float) $demand['total_price_tax_excl'],
                    'unit_price_tax_incl' => (float) $demand['total_price_tax_incl'],
                    'type' => 'charge',
                    'source' => 'demand',
                );
                $demandsTaxExcl += (float) $demand['total_price_tax_excl'];
                $demandsTaxIncl += (float) $demand['total_price_tax_incl'];
            }
        }

        // Added service products (per-night services etc) for this booking.
        $servicesTaxIncl = 0.0;
        $servicesTaxExcl = 0.0;
        $serviceItems = array();
        $serviceRows = Db::getInstance()->executeS(
            'SELECT `spod`.`name`, `spod`.`quantity`, `spod`.`unit_price_tax_excl`,
                    `spod`.`unit_price_tax_incl`, `spod`.`total_price_tax_excl`,
                    `spod`.`total_price_tax_incl`, `spod`.`is_cancelled`, `spod`.`is_refunded`
             FROM `'._DB_PREFIX_.'service_product_order_detail` `spod`
             WHERE `spod`.`id_htl_booking_detail` = '.(int) $this->idHtlBooking.'
             ORDER BY `spod`.`id_service_product_order_detail` ASC'
        );
        if ($serviceRows) {
            foreach ($serviceRows as $service) {
                if ((int) $service['is_cancelled'] || (int) $service['is_refunded']) {
                    continue;
                }
                $serviceItems[] = array(
                    'label' => $service['name'],
                    'qty' => (int) $service['quantity'],
                    'unit_price_tax_excl' => (float) $service['unit_price_tax_excl'],
                    'unit_price_tax_incl' => (float) $service['unit_price_tax_incl'],
                    'type' => 'charge',
                    'source' => 'service',
                );
                $servicesTaxExcl += (float) $service['total_price_tax_excl'];
                $servicesTaxIncl += (float) $service['total_price_tax_incl'];
            }
        }

        $folioLines = array();
        foreach (QlofdFolioLine::getForBooking($this->idHtlBooking) as $line) {
            $folioLines[] = array(
                'id' => (int) $line['id'],
                'label' => $line['label'],
                'qty' => (int) $line['qty'],
                'unit_price_tax_excl' => (float) $line['unit_price_tax_excl'],
                'unit_price_tax_incl' => (float) $line['unit_price_tax_incl'],
                'type' => $line['type'],
                'source' => 'custom',
            );
        }

        $payments = array();
        $totalPaid = 0.0;

        // Payments recorded against the order (order_payment rows).
        $orderPaymentRows = array();
        if (Validate::isLoadedObject($objOrder)) {
            $orderPaymentRows = Db::getInstance()->executeS(
                'SELECT `op`.`id_order_payment` AS `id_order_payment`, `op`.`amount`, `op`.`payment_method`,
                        `op`.`date_add`, `op`.`transaction_id` AS `reference`,
                        `c`.`sign` AS `currency_sign`, `c`.`iso_code`
                 FROM `'._DB_PREFIX_.'order_payment` `op`
                 LEFT JOIN `'._DB_PREFIX_.'currency` `c` ON (`c`.`id_currency` = `op`.`id_currency`)
                 WHERE `op`.`order_reference` = \''.pSQL($objOrder->reference).'\'
                 ORDER BY `op`.`id_order_payment` ASC'
            );
        }

        // Folio payments recorded before an order existed (orphans).
        $orphanPayments = Db::getInstance()->executeS(
            'SELECT 0 AS `id_order_payment`, `amount`, `payment_method`, `reference`,
                    `date_add`, `c`.`sign` AS `currency_sign`, `c`.`iso_code`
             FROM `'._DB_PREFIX_.'qlofd_folio_payment` `qp`
             LEFT JOIN `'._DB_PREFIX_.'currency` `c` ON (`c`.`id_currency` = `qp`.`id_currency`)
             WHERE `qp`.`id_htl_booking` = '.(int) $this->idHtlBooking.'
             AND `qp`.`id_order_payment` = 0
             ORDER BY `qp`.`id` ASC'
        );

        foreach (array_merge($orderPaymentRows, $orphanPayments) as $payment) {
            $payments[] = array(
                'id_order_payment' => (int) $payment['id_order_payment'],
                'amount' => (float) $payment['amount'],
                'payment_method' => $payment['payment_method'],
                'reference' => $payment['reference'],
                'currency_sign' => $payment['currency_sign'],
                'iso_code' => $payment['iso_code'],
                'date_add' => $payment['date_add'],
            );
            $totalPaid += (float) $payment['amount'];
        }

        $folioLineTotals = QlofdFolioLine::getTotalsForBooking($this->idHtlBooking);
        $chargesTaxIncl = $roomChargeTaxIncl + $demandsTaxIncl + $servicesTaxIncl + $folioLineTotals['total_tax_incl'];
        $chargesTaxExcl = $roomChargeTaxExcl + $demandsTaxExcl + $servicesTaxExcl + $folioLineTotals['total_tax_excl'];
        $balance = $chargesTaxIncl - $totalPaid;

        return array(
            'id_htl_booking' => $this->idHtlBooking,
            'id_order' => (int) $objBooking->id_order,
            'id_customer' => (int) $objBooking->id_customer,
            'customer' => $customerName,
            'room_num' => $objBooking->room_num,
            'room_type_name' => $objBooking->room_type_name,
            'date_from' => $objBooking->date_from,
            'date_to' => $objBooking->date_to,
            'id_currency' => $idCurrency,
            'currency_sign' => $currency ? $currency->sign : '',
            'currency_iso' => $currency ? $currency->iso_code : '',
            'room_charge_tax_incl' => $roomChargeTaxIncl,
            'room_charge_tax_excl' => $roomChargeTaxExcl,
            'demands' => $demandItems,
            'demands_tax_incl' => $demandsTaxIncl,
            'services' => $serviceItems,
            'services_tax_incl' => $servicesTaxIncl,
            'folio_lines' => $folioLines,
            'folio_line_totals' => $folioLineTotals,
            'payments' => $payments,
            'total_paid' => $totalPaid,
            'charges_tax_incl' => $chargesTaxIncl,
            'charges_tax_excl' => $chargesTaxExcl,
            'balance' => $balance,
            'payment_status' => $paymentStatus,
        );
    }
}