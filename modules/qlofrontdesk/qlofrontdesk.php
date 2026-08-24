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
 *
 * @author QloApps Contributors
 * @copyright Since 2010
 * @license https://opensource.org/license/osl-3-0-php Open Software License version 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/define.php';

/**
 * Front Desk module for QloApps Hotel PMS.
 *
 * Provides a room-by-date grid for operations: create/move/swap bookings,
 * folio billing, housekeeping, OOO handling, undo and rule-based helpers
 * (no-show risk, rate parity, auto room assignment).
 */
class Qlofrontdesk extends Module
{
    public function __construct()
    {
        $this->name = 'qlofrontdesk';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'QloApps Contributors';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Front Desk');
        $this->description = $this->l('Hotel operations grid: bookings, folio billing, housekeeping, OOO and rule-based helpers.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Front Desk module? All module data will be deleted.');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        $objDb = new QlofdDb();
        if (!parent::install()
            || !$objDb->createTables()
            || !$this->registerModuleHooks()
            || !$this->installDefaultConfig()
            || !$this->callInstallTab()
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $objDb = new QlofdDb();
        if (!parent::uninstall()
            || !$this->uninstallTab()
            || !$objDb->dropTables()
            || !$this->deleteConfigKeys()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Register third-party hooks used by this module.
     *
     * @return bool
     */
    public function registerModuleHooks()
    {
        return $this->registerHook(
            array(
                'displayBackOfficeHeader',
            )
        );
    }

    /**
     * Create the root "Front Desk" tab (positioned first) and its settings child tab.
     *
     * @return bool
     */
    public function callInstallTab()
    {
        $this->installTab('AdminFrontDesk', 'Front Desk', false, true);
        $this->installTab('AdminFrontDeskSettings', 'Front Desk Settings', 'AdminFrontDesk');

        return true;
    }

    /**
     * @param string $class_name       Controller class name (tab routing key)
     * @param string $tab_name         Display name of the tab
     * @param string|bool $tabParentName Parent tab class name, false for root
     * @param bool $positionFirst      Move the tab to the top of its parent group
     * @return bool
     */
    public function installTab($class_name, $tab_name, $tabParentName = false, $positionFirst = false)
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $class_name;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tab_name;
        }

        if ($tabParentName) {
            $tab->id_parent = (int) Tab::getIdFromClassName($tabParentName);
        } else {
            $tab->id_parent = 0;
        }

        $tab->module = $this->name;
        if (!$tab->add()) {
            return false;
        }

        if ($positionFirst) {
            $objTab = new Tab($tab->id);
            $objTab->updatePosition(0, 0);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function uninstallTab()
    {
        $moduleTabs = Tab::getCollectionFromModule($this->name);
        if (!empty($moduleTabs)) {
            foreach ($moduleTabs as $moduleTab) {
                $moduleTab->delete();
            }
        }

        return true;
    }

    /**
     * Default Configuration values for the module.
     *
     * @return bool
     */
    public function installDefaultConfig()
    {
        $config = array(
            'QLOFD_WINDOW_DAYS' => 14,
            'QLOFD_GROUP_BOOKING_THRESHOLD' => 2,
            'QLOFD_PARITY_MARGIN_PCT' => 5,
            'QLOFD_PARITY_COMMISSION_MIN' => 15,
            'QLOFD_PARITY_COMMISSION_MAX' => 25,
            'QLOFD_NO_SHOW_LEAD_TIME_W' => 20,
            'QLOFD_NO_SHOW_NO_PAYMENT_W' => 35,
            'QLOFD_NO_SHOW_CHANNEL_W' => 10,
            'QLOFD_NO_SHOW_FIRST_TIME_W' => 10,
            'QLOFD_NO_SHOW_SAME_DAY_W' => 15,
            'QLOFD_NO_SHOW_GROUP_W' => 10,
            'QLOFD_NO_SHOW_LOW_THRESHOLD' => 0.4,
            'QLOFD_NO_SHOW_HIGH_THRESHOLD' => 0.7,
            'QLOFD_UNDO_LIMIT' => 10,
        );

        foreach ($config as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function deleteConfigKeys()
    {
        $configKeys = array(
            'QLOFD_WINDOW_DAYS',
            'QLOFD_GROUP_BOOKING_THRESHOLD',
            'QLOFD_PARITY_MARGIN_PCT',
            'QLOFD_PARITY_COMMISSION_MIN',
            'QLOFD_PARITY_COMMISSION_MAX',
            'QLOFD_NO_SHOW_LEAD_TIME_W',
            'QLOFD_NO_SHOW_NO_PAYMENT_W',
            'QLOFD_NO_SHOW_CHANNEL_W',
            'QLOFD_NO_SHOW_FIRST_TIME_W',
            'QLOFD_NO_SHOW_SAME_DAY_W',
            'QLOFD_NO_SHOW_GROUP_W',
            'QLOFD_NO_SHOW_LOW_THRESHOLD',
            'QLOFD_NO_SHOW_HIGH_THRESHOLD',
            'QLOFD_UNDO_LIMIT',
        );

        foreach ($configKeys as $key) {
            if (!Configuration::deleteByName($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inject module admin assets on back office pages.
     *
     * @return void
     */
    public function hookDisplayBackOfficeHeader()
    {
        $controller = $this->context->controller;
        if (empty($controller)) {
            return;
        }

        if (in_array($controller->controller_name, array('AdminFrontDesk', 'AdminFrontDeskSettings')) && method_exists($controller, 'addCSS')) {
            $controller->addCSS(_PS_MODULE_DIR_.$this->name.'/views/css/admin/front_desk.css');
        }
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminFrontDeskSettings'));
    }

    /**
     * Register tasks with QloCronTaskManager (installed module dispatches these).
     *
     * @return array
     */
    public function hookRegisterCronTasks()
    {
        return array(
            array(
                'name' => 'qlofrontdesk-rate-parity',
                'description' => 'Check own rates against stored OTA rates and raise parity alerts.',
                'cron' => '0 */3 * * *',
                'callback' => 'runRateParityJob',
            ),
            array(
                'name' => 'qlofrontdesk-no-show-risk',
                'description' => 'Refresh no-show risk scores for upcoming arrivals.',
                'cron' => '0 3 * * *',
                'callback' => 'runNoShowRiskJob',
            ),
            array(
                'name' => 'qlofrontdesk-housekeeping-cleanup',
                'description' => 'Flag checked-out rooms as dirty for housekeeping.',
                'cron' => '0 12 * * *',
                'callback' => 'runHousekeepingDirtyJob',
            ),
        );
    }

    /**
     * Rate parity job: compare live own rates with qlofd_ota_rate rows.
     *
     * @return void
     */
    public function runRateParityJob()
    {
        $objParity = new QlofdRateParity();
        return $objParity->runParityCheck();
    }

    /**
     * No-show risk job: score upcoming arrivals in batch.
     *
     * @return void
     */
    public function runNoShowRiskJob()
    {
        $objNoShow = new QlofdNoShowRisk();
        return $objNoShow->runBatchScoring();
    }

    /**
     * Housekeeping job: mark rooms of checked-out bookings as dirty.
     *
     * @return void
     */
    public function runHousekeepingDirtyJob()
    {
        $objLog = new QlofdHousekeepingLog();
        return $objLog->markCheckedOutRoomsDirty();
    }
}