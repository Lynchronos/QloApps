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

class QlofdDb
{
    /**
     * DDL statements for all module tables. Core htl_* tables are never
     * touched by this module - only additive qlofd_* extension tables.
     *
     * @return array
     */
    public function getModuleSql()
    {
        return array(
            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_booking_extra` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_htl_booking` int(11) NOT NULL,
                `is_locked_assignment` tinyint(1) NOT NULL DEFAULT '0',
                `is_group_booking` tinyint(1) NOT NULL DEFAULT '0',
                `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
                `is_no_show` tinyint(1) NOT NULL DEFAULT '0',
                `no_show_risk_score` decimal(4,3) DEFAULT NULL,
                `no_show_risk_label` enum('low','medium','high') DEFAULT NULL,
                `no_show_risk_updated_at` datetime DEFAULT NULL,
                `date_add` datetime NOT NULL,
                `date_upd` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_id_htl_booking` (`id_htl_booking`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_housekeeping_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_room` int(11) NOT NULL,
                `status` enum('clean','dirty','inspected','do_not_disturb') NOT NULL,
                `note` text,
                `id_employee` int(11) NOT NULL DEFAULT '0',
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_room_date` (`id_room`, `date_add`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_audit_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `entity_type` varchar(32) NOT NULL,
                `entity_id` int(11) NOT NULL,
                `action` varchar(64) NOT NULL,
                `before_data` text,
                `after_data` text,
                `id_employee` int(11) NOT NULL DEFAULT '0',
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_entity` (`entity_type`, `entity_id`),
                KEY `idx_date_add` (`date_add`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_folio_line` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_htl_booking` int(11) NOT NULL,
                `label` varchar(255) NOT NULL,
                `qty` int(11) NOT NULL DEFAULT '1',
                `unit_price_tax_excl` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `unit_price_tax_incl` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `type` enum('charge','credit') NOT NULL DEFAULT 'charge',
                `id_employee` int(11) NOT NULL DEFAULT '0',
                `date_add` datetime NOT NULL,
                `date_upd` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_htl_booking` (`id_htl_booking`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_folio_payment` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_htl_booking` int(11) NOT NULL,
                `id_order_payment` int(11) NOT NULL DEFAULT '0',
                `amount` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `payment_method` varchar(128) NOT NULL DEFAULT '',
                `reference` varchar(128) NOT NULL DEFAULT '',
                `id_currency` int(11) NOT NULL DEFAULT '0',
                `id_employee` int(11) NOT NULL DEFAULT '0',
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_htl_booking` (`id_htl_booking`),
                KEY `idx_order_payment` (`id_order_payment`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_ota_rate` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_hotel` int(11) NOT NULL,
                `id_product` int(11) NOT NULL,
                `date` date NOT NULL,
                `currency` varchar(8) NOT NULL DEFAULT '',
                `ota_name` varchar(128) NOT NULL,
                `rate` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `commission_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
                `date_add` datetime NOT NULL,
                `date_upd` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ota_rate` (`id_hotel`, `id_product`, `date`, `ota_name`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",

            "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."qlofd_parity_alert` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `id_hotel` int(11) NOT NULL,
                `id_product` int(11) NOT NULL,
                `date` date NOT NULL,
                `own_rate` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `ota_rate` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `ota_name` varchar(128) NOT NULL DEFAULT '',
                `issue_type` enum('direct_not_competitive','ota_more_profitable') NOT NULL,
                `resolved` tinyint(1) NOT NULL DEFAULT '0',
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_hotel_product_date` (`id_hotel`, `id_product`, `date`)
            ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",
        );
    }

    /**
     * Create module tables.
     *
     * @return bool
     */
    public function createTables()
    {
        if ($sql = $this->getModuleSql()) {
            foreach ($sql as $query) {
                if ($query) {
                    if (!Db::getInstance()->execute(trim($query))) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Drop module tables (uninstall path only).
     *
     * @return bool
     */
    public function dropTables()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS
            `'._DB_PREFIX_.'qlofd_booking_extra`,
            `'._DB_PREFIX_.'qlofd_housekeeping_log`,
            `'._DB_PREFIX_.'qlofd_audit_log`,
            `'._DB_PREFIX_.'qlofd_folio_line`,
            `'._DB_PREFIX_.'qlofd_folio_payment`,
            `'._DB_PREFIX_.'qlofd_ota_rate`,
            `'._DB_PREFIX_.'qlofd_parity_alert`'
        );
    }
}