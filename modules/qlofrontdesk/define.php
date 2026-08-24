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
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to a newer
 * versions in the future.
 */

// Hotel reservation system must be installed first - it provides all core
// hotel classes (htl_* tables, booking flow, pricing) consumed by this module.
require_once _PS_MODULE_DIR_.'hotelreservationsystem/define.php';

require_once dirname(__FILE__).'/classes/QlofdDb.php';
require_once dirname(__FILE__).'/classes/QlofdBookingExtra.php';
require_once dirname(__FILE__).'/classes/QlofdHousekeepingLog.php';
require_once dirname(__FILE__).'/classes/QlofdAuditLog.php';
require_once dirname(__FILE__).'/classes/QlofdFolioLine.php';
require_once dirname(__FILE__).'/classes/QlofdFolioPayment.php';
require_once dirname(__FILE__).'/classes/QlofdFolioService.php';
require_once dirname(__FILE__).'/classes/QlofdGrid.php';
require_once dirname(__FILE__).'/classes/QlofdBookingFlow.php';
require_once dirname(__FILE__).'/classes/QlofdNoShowRisk.php';
require_once dirname(__FILE__).'/classes/QlofdRateParity.php';
require_once dirname(__FILE__).'/classes/QlofdRoomAssignment.php';
require_once dirname(__FILE__).'/classes/QlofdUndo.php';
require_once dirname(__FILE__).'/classes/QlofdReceiptPdf.php';