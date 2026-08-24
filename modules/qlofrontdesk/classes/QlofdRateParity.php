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
 * Rate-parity comparison against stored OTA rates, plus alert management.
 * Own rates are always read live from hotel pricing - never copied.
 */
class QlofdRateParity
{
    const ISSUE_DIRECT_NOT_COMPETITIVE = 'direct_not_competitive';
    const ISSUE_OTA_MORE_PROFITABLE = 'ota_more_profitable';

    /**
     * Compare live own rates with qlofd_ota_rate rows and upsert alerts.
     *
     * @return int number of open alerts after the run
     */
    public function runParityCheck()
    {
        $marginPct = max(0, (float) Configuration::get('QLOFD_PARITY_MARGIN_PCT'));
        $minCommission = (float) Configuration::get('QLOFD_PARITY_COMMISSION_MIN');
        $maxCommission = (float) Configuration::get('QLOFD_PARITY_COMMISSION_MAX');

        $dateFrom = date('Y-m-d', strtotime('-14 days'));
        $dateTo = date('Y-m-d', strtotime('+14 days'));

        $rates = Db::getInstance()->executeS(
            'SELECT `id_hotel`, `id_product`, `date`, `currency`, `ota_name`, `rate`, `commission_pct` 
             FROM `'._DB_PREFIX_.'qlofd_ota_rate`
             WHERE `date` >= \''.pSQL($dateFrom).'\'
             AND `date` <= \''.pSQL($dateTo).'\''
        );

        if (!$rates) {
            return $this->countOpenAlerts();
        }

        $occupancy = array(array('adults' => 2, 'children' => 0, 'child_ages' => array()));
        $now = date('Y-m-d H:i:s');

        foreach ($rates as $rate) {
            $idProduct = (int) $rate['id_product'];
            $otaRate = (float) $rate['rate'];
            $date = $rate['date'];
            $dayAfter = date('Y-m-d', strtotime($date.' +1 day'));

            $own = HotelRoomTypeFeaturePricing::getRoomTypeTotalPrice(
                $idProduct,
                $date,
                $dayAfter,
                $occupancy,
                0,
                0,
                0,
                0,
                0,
                1
            );
            $ownRate = isset($own['total_price_tax_incl']) ? (float) $own['total_price_tax_incl'] : 0.0;

            if ($ownRate > 0 && $otaRate > 0) {
                $commission = max($minCommission, min($maxCommission, (float) $rate['commission_pct']));
                $otaNet = $otaRate * (1 - ($commission / 100));

                $issues = array();
                if ($ownRate >= $otaRate * (1 + ($marginPct / 100))) {
                    $issues[] = self::ISSUE_DIRECT_NOT_COMPETITIVE;
                }
                if ($otaNet > $ownRate) {
                    $issues[] = self::ISSUE_OTA_MORE_PROFITABLE;
                }

                foreach ($issues as $issue) {
                    $this->upsertAlert(
                        (int) $rate['id_hotel'],
                        $idProduct,
                        $date,
                        $ownRate,
                        $otaRate,
                        $rate['ota_name'],
                        $issue,
                        $now
                    );
                }
            }
        }

        return $this->countOpenAlerts();
    }

    /**
     * @param int $idHotel
     * @param int $idProduct
     * @param string $date
     * @param float $ownRate
     * @param float $otaRate
     * @param string $otaName
     * @param string $issueType
     * @param string $now
     * @return void
     */
    protected function upsertAlert($idHotel, $idProduct, $date, $ownRate, $otaRate, $otaName, $issueType, $now)
    {
        $existingId = (int) Db::getInstance()->getValue(
            'SELECT `id` FROM `'._DB_PREFIX_.'qlofd_parity_alert`
             WHERE `id_hotel` = '.(int) $idHotel.'
             AND `id_product` = '.(int) $idProduct.'
             AND `date` = \''.pSQL($date).'\'
             AND `ota_name` = \''.pSQL($otaName).'\'
             AND `issue_type` = \''.pSQL($issueType).'\''
        );

        if ($existingId) {
            Db::getInstance()->update('qlofd_parity_alert', array(
                'own_rate' => (float) $ownRate,
                'ota_rate' => (float) $otaRate,
            ), '`id` = '.(int) $existingId);
            return;
        }

        Db::getInstance()->insert('qlofd_parity_alert', array(
            'id_hotel' => (int) $idHotel,
            'id_product' => (int) $idProduct,
            'date' => pSQL($date),
            'own_rate' => (float) $ownRate,
            'ota_rate' => (float) $otaRate,
            'ota_name' => pSQL($otaName),
            'issue_type' => pSQL($issueType),
            'resolved' => 0,
            'date_add' => pSQL($now),
        ));
    }

    /**
     * Bulk-import OTA rates.
     *
     * @param array $rows
     * @param bool $replace update rows on unique conflict
     * @return array ['inserted' => int, 'updated' => int]
     */
    public function importOtaRates($rows, $replace = false)
    {
        $inserted = 0;
        $updated = 0;

        if (empty($rows)) {
            return array('inserted' => $inserted, 'updated' => $updated);
        }

        foreach ($rows as $row) {
            $idHotel = (int) $row['id_hotel'];
            $idProduct = (int) $row['id_product'];
            $date = Validate::isDate($row['date']) ? $row['date'] : '';
            $otaName = trim($row['ota_name']);
            $rate = (float) $row['rate'];
            $commission = (float) $row['commission_pct'];

            if (!$idHotel || !$idProduct || !$date || !$otaName || $rate < 0) {
                continue;
            }

            $exists = Db::getInstance()->getValue(
                'SELECT `id` FROM `'._DB_PREFIX_.'qlofd_ota_rate`
                 WHERE `id_hotel` = '.(int) $idHotel.'
                 AND `id_product` = '.(int) $idProduct.'
                 AND `date` = \''.pSQL($date).'\'
                 AND `ota_name` = \''.pSQL($otaName).'\''
            );

            if ($exists && !$replace) {
                continue;
            }

            if ($exists) {
                $result = Db::getInstance()->update('qlofd_ota_rate', array(
                    'currency' => pSQL($row['currency']),
                    'rate' => $rate,
                    'commission_pct' => $commission,
                    'date_upd' => date('Y-m-d H:i:s'),
                ), '`id` = '.(int) $exists);
                if ($result) {
                    $updated++;
                }
            } else {
                $result = Db::getInstance()->insert('qlofd_ota_rate', array(
                    'id_hotel' => $idHotel,
                    'id_product' => $idProduct,
                    'date' => pSQL($date),
                    'currency' => pSQL($row['currency']),
                    'ota_name' => pSQL($otaName),
                    'rate' => $rate,
                    'commission_pct' => $commission,
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ));
                if ($result) {
                    $inserted++;
                }
            }
        }

        return array('inserted' => $inserted, 'updated' => $updated);
    }

    /**
     * @param int $idAlert
     * @param bool $resolved
     * @return bool
     */
    public function toggleAlertResolved($idAlert, $resolved)
    {
        return (bool) Db::getInstance()->update(
            'qlofd_parity_alert',
            array('resolved' => $resolved ? 1 : 0),
            '`id` = '.(int) $idAlert
        );
    }

    /**
     * @return int
     */
    public function countOpenAlerts()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `'._DB_PREFIX_.'qlofd_parity_alert` WHERE `resolved` = 0'
        );
    }
}