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
 * Weighted heuristic no-show risk scoring for upcoming arrivals.
 * Features: lead time, payment status, channel, repeat guest,
 * same-day booking and group size.
 */
class QlofdNoShowRisk
{
    /** @var Module */
    protected $module;

    public function __construct()
    {
        $this->module = Module::getInstanceByName('qlofrontdesk');
    }

    /**
     * Score a single booking, store the result and return it.
     *
     * @param int $idHtlBooking
     * @return array ['score' => float, 'label' => string]
     */
    public function scoreForBooking($idHtlBooking)
    {
        $objBooking = new HotelBookingDetail((int) $idHtlBooking);
        if (!Validate::isLoadedObject($objBooking)) {
            return array('score' => 0.0, 'label' => 'low');
        }

        $features = $this->extractFeatures($objBooking);
        $score = $this->computeScore($features);

        $objExtra = QlofdBookingExtra::getForBooking((int) $idHtlBooking);
        $objExtra->no_show_risk_score = $score;
        $objExtra->no_show_risk_label = $this->labelForScore($score);
        $objExtra->no_show_risk_updated_at = date('Y-m-d H:i:s');
        $objExtra->save();

        return array('score' => $score, 'label' => $objExtra->no_show_risk_label);
    }

    /**
     * Batch-score upcoming arrivals (next 72h). Runs daily via cron.
     *
     * @return int
     */
    public function runBatchScoring()
    {
        $dateFrom = date('Y-m-d 00:00:00');
        $dateTo = date('Y-m-d H:i:s', strtotime('+72 hours'));

        $rows = Db::getInstance()->executeS(
            'SELECT `bd`.`id`
             FROM `'._DB_PREFIX_.'htl_booking_detail` `bd`
             WHERE `bd`.`id_status` = '.(int) HotelBookingDetail::STATUS_ALLOTED.'
             AND `bd`.`is_cancelled` = 0
             AND `bd`.`date_from` >= \''.pSQL($dateFrom).'\'
             AND `bd`.`date_from` < \''.pSQL($dateTo).'\''
        );

        $count = 0;
        if ($rows) {
            foreach ($rows as $row) {
                $this->scoreForBooking((int) $row['id']);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param HotelBookingDetail $objBooking
     * @return array
     */
    protected function extractFeatures($objBooking)
    {
        $now = time();
        $arrival = strtotime($objBooking->date_from);
        $leadDays = max(0, (int) floor(($arrival - strtotime($objBooking->date_add)) / 86400));

        $isSameDay = date('Y-m-d', strtotime($objBooking->date_add)) === date('Y-m-d', $arrival);

        // Use real order_payment rows: htl_booking_detail.total_paid_amount is
        // set to the full booking price by validateOrder and is not a payment.
        $isNoPayment = 1;
        $objOrder = new Order((int) $objBooking->id_order);
        if (Validate::isLoadedObject($objOrder)) {
            $paidAmount = (float) Db::getInstance()->getValue(
                'SELECT SUM(`amount`) FROM `'._DB_PREFIX_.'order_payment`
                 WHERE `order_reference` = \''.pSQL($objOrder->reference).'\''
            );
            $isNoPayment = $paidAmount <= 0 ? 1 : 0;
        }

        $isChannel = 0;
        if (Validate::isLoadedObject($objOrder) && in_array($objOrder->source, array('Channel Manager Booking', 'channel', 'expedia', 'booking.com'))) {
            $isChannel = 1;
        }

        $isRepeatGuest = 0;
        if ((int) $objBooking->id_customer) {
            $priorOrders = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `'._DB_PREFIX_.'orders`
                 WHERE `id_customer` = '.(int) $objBooking->id_customer.'
                 AND `id_order` < '.(int) $objBooking->id_order
            );
            $isRepeatGuest = $priorOrders > 0 ? 1 : 0;
        }

        $groupSize = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `'._DB_PREFIX_.'htl_booking_detail`
             WHERE `id_order` = '.(int) $objBooking->id_order.'
             AND `is_cancelled` = 0'
        );

        return array(
            'leadDays' => $leadDays,
            'isSameDay' => $isSameDay,
            'isNoPayment' => $isNoPayment,
            'isChannel' => $isChannel,
            'isRepeatGuest' => $isRepeatGuest,
            'groupSize' => $groupSize,
        );
    }

    /**
     * @param array $f
     * @return float 0..1
     */
    protected function computeScore($f)
    {
        $wLead = max(0, (int) Configuration::get('QLOFD_NO_SHOW_LEAD_TIME_W'));
        $wNoPay = max(0, (int) Configuration::get('QLOFD_NO_SHOW_NO_PAYMENT_W'));
        $wChannel = max(0, (int) Configuration::get('QLOFD_NO_SHOW_CHANNEL_W'));
        $wFirstTime = max(0, (int) Configuration::get('QLOFD_NO_SHOW_FIRST_TIME_W'));
        $wSameDay = max(0, (int) Configuration::get('QLOFD_NO_SHOW_SAME_DAY_W'));
        $wGroup = max(0, (int) Configuration::get('QLOFD_NO_SHOW_GROUP_W'));

        $totalWeight = $wLead + $wNoPay + $wChannel + $wFirstTime + $wSameDay + $wGroup;
        if ($totalWeight <= 0) {
            return 0.0;
        }

        $leadComponent = max(0, 1 - ($f['leadDays'] / 30));
        $groupComponent = $f['groupSize'] >= 2 ? 1 : 0;

        $score = 0.0;
        $score += $wLead * $leadComponent;
        $score += $wNoPay * ($f['isNoPayment'] ? 1 : 0);
        $score += $wChannel * $f['isChannel'];
        $score += $wFirstTime * ($f['isRepeatGuest'] ? 0 : 1);
        $score += $wSameDay * ($f['isSameDay'] ? 1 : 0);
        $score += $wGroup * $groupComponent;

        return round($score / $totalWeight, 3);
    }

    /**
     * @param float $score
     * @return string low|medium|high
     */
    public function labelForScore($score)
    {
        $low = (float) Configuration::get('QLOFD_NO_SHOW_LOW_THRESHOLD');
        $high = (float) Configuration::get('QLOFD_NO_SHOW_HIGH_THRESHOLD');

        if ($score >= $high) {
            return 'high';
        }
        if ($score >= $low) {
            return 'medium';
        }

        return 'low';
    }
}