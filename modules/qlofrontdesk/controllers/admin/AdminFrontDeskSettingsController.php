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

class AdminFrontDeskSettingsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();

        parent::__construct();
    }

    public function setMedia()
    {
        parent::setMedia();
        $this->addCSS(_PS_MODULE_DIR_.$this->module->name.'/views/css/admin/front_desk.css');
    }

    public function initContent()
    {
        if (!$this->tabAccess['view']) {
            $this->errors[] = $this->l('You do not have permission to view this.');
            return;
        }

        $this->toolbar_title = $this->l('Front Desk Settings');
        $this->display = 'view';

        parent::initContent();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitQlofdSettings') && $this->tabAccess['edit'] === 1) {
            $this->saveSettings();
        }

        if (Tools::isSubmit('submitQlofdImportOta') && $this->tabAccess['edit'] === 1) {
            $this->importOtaCsv();
        }

        if (Tools::isSubmit('submitQlofdRunParity') && $this->tabAccess['edit'] === 1) {
            $objParity = new QlofdRateParity();
            $open = $objParity->runParityCheck();
            $this->confirmations[] = sprintf($this->l('Parity check completed. %d open alert(s).'), $open);
        }

        if (Tools::isSubmit('submitQlofdResolveAlert') && $this->tabAccess['edit'] === 1) {
            $objParity = new QlofdRateParity();
            if ($objParity->toggleAlertResolved((int) Tools::getValue('id_alert'), (bool) Tools::getValue('resolved'))) {
                $this->confirmations[] = $this->l('Alert updated.');
            } else {
                $this->errors[] = $this->l('Unable to update the alert.');
            }
        }

        parent::postProcess();
    }

    protected function saveSettings()
    {
        $fields = array(
            'QLOFD_WINDOW_DAYS' => array('validate' => 'isUnsignedInt', 'min' => 7, 'max' => 21),
            'QLOFD_GROUP_BOOKING_THRESHOLD' => array('validate' => 'isUnsignedInt', 'min' => 1, 'max' => 20),
            'QLOFD_PARITY_MARGIN_PCT' => array('validate' => 'isFloat', 'min' => 0, 'max' => 100),
            'QLOFD_PARITY_COMMISSION_MIN' => array('validate' => 'isFloat', 'min' => 0, 'max' => 100),
            'QLOFD_PARITY_COMMISSION_MAX' => array('validate' => 'isFloat', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_LEAD_TIME_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_NO_PAYMENT_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_CHANNEL_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_FIRST_TIME_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_SAME_DAY_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_GROUP_W' => array('validate' => 'isUnsignedInt', 'min' => 0, 'max' => 100),
            'QLOFD_NO_SHOW_LOW_THRESHOLD' => array('validate' => 'isFloat', 'min' => 0, 'max' => 1),
            'QLOFD_NO_SHOW_HIGH_THRESHOLD' => array('validate' => 'isFloat', 'min' => 0, 'max' => 1),
            'QLOFD_UNDO_LIMIT' => array('validate' => 'isUnsignedInt', 'min' => 1, 'max' => 50),
        );

        foreach ($fields as $key => $rule) {
            $value = Tools::getValue($key);
            $validator = $rule['validate'];
            if (!Validate::$validator($value)) {
                $this->errors[] = sprintf($this->l('Invalid value for %s.'), $key);
                continue;
            }
            $value = (float) $value;
            if ($value < $rule['min'] || $value > $rule['max']) {
                $this->errors[] = sprintf($this->l('Value out of range for %s.'), $key);
                continue;
            }
        }

        if (!count($this->errors)) {
            foreach ($fields as $key => $rule) {
                Configuration::updateValue($key, (float) Tools::getValue($key));
            }
            $this->confirmations[] = $this->l('Settings saved.');
        }
    }

    protected function importOtaCsv()
    {
        if (!isset($_FILES['ota_csv']) || $_FILES['ota_csv']['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->l('Please upload a CSV file.');
            return;
        }

        $handle = fopen($_FILES['ota_csv']['tmp_name'], 'r');
        if (!$handle) {
            $this->errors[] = $this->l('Unable to read the uploaded file.');
            return;
        }

        $rows = array();
        $firstLine = true;
        while (($line = fgetcsv($handle)) !== false) {
            if ($firstLine) {
                $firstLine = false;
                if (strtolower(trim($line[0])) === 'id_hotel' || strtolower(trim($line[0])) === 'hotel_id') {
                    continue;
                }
            }
            if (count($line) < 6) {
                continue;
            }

            $rows[] = array(
                'id_hotel' => (int) trim($line[0]),
                'id_product' => (int) trim($line[1]),
                'date' => trim($line[2]),
                'currency' => trim($line[3]),
                'ota_name' => trim($line[4]),
                'rate' => (float) trim($line[5]),
                'commission_pct' => isset($line[6]) ? (float) trim($line[6]) : 0.0,
            );
        }
        fclose($handle);

        if (empty($rows)) {
            $this->errors[] = $this->l('No valid rows found in the CSV. Expected columns: id_hotel, id_product, date, currency, ota_name, rate, commission_pct.');
            return;
        }

        $objParity = new QlofdRateParity();
        $result = $objParity->importOtaRates($rows, Tools::getValue('replace_rates') ? true : false);
        $this->confirmations[] = sprintf(
            $this->l('Import complete: %d inserted, %d updated.'),
            $result['inserted'],
            $result['updated']
        );
    }

    public function renderView()
    {
        $helper = $this->buildSettingsForm();
        $this->content = $helper->generateForm($this->getSettingsFields());
        $this->content .= $this->renderCsvImportPanel();
        $this->content .= $this->renderParityAlerts();
        $this->content .= $this->renderAuditLog();

        return $this->content;
    }

    protected function buildSettingsForm()
    {
        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->controller_name;
        $helper->token = Tools::getAdminTokenLite($this->controller_name);
        $helper->currentIndex = self::$currentIndex;
        $helper->default_form_language = $idLangDefault;
        $helper->submit_action = 'submitQlofdSettings';
        $helper->title = $this->l('Front Desk Settings');
        $helper->show_toolbar = true;

        $keys = array(
            'QLOFD_WINDOW_DAYS', 'QLOFD_GROUP_BOOKING_THRESHOLD',
            'QLOFD_PARITY_MARGIN_PCT', 'QLOFD_PARITY_COMMISSION_MIN', 'QLOFD_PARITY_COMMISSION_MAX',
            'QLOFD_NO_SHOW_LEAD_TIME_W', 'QLOFD_NO_SHOW_NO_PAYMENT_W', 'QLOFD_NO_SHOW_CHANNEL_W',
            'QLOFD_NO_SHOW_FIRST_TIME_W', 'QLOFD_NO_SHOW_SAME_DAY_W', 'QLOFD_NO_SHOW_GROUP_W',
            'QLOFD_NO_SHOW_LOW_THRESHOLD', 'QLOFD_NO_SHOW_HIGH_THRESHOLD',
            'QLOFD_UNDO_LIMIT',
        );

        $values = array();
        foreach ($keys as $key) {
            $values[$key] = Configuration::get($key);
        }
        $helper->fields_value = $values;

        return $helper;
    }

    protected function getSettingsFields()
    {
        return array(
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Grid'),
                        'icon' => 'icon-th-large',
                    ),
                    'input' => array(
                        array(
                            'type' => 'text',
                            'label' => $this->l('Grid window (days)'),
                            'name' => 'QLOFD_WINDOW_DAYS',
                            'col' => 3,
                            'suffix' => $this->l('days'),
                            'desc' => $this->l('Number of dates shown in the room timeline (7–21).'),
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Group booking threshold'),
                            'name' => 'QLOFD_GROUP_BOOKING_THRESHOLD',
                            'col' => 3,
                            'desc' => $this->l('Bookings sharing one order whose count reaches this value are flagged as a group.'),
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Undo limit'),
                            'name' => 'QLOFD_UNDO_LIMIT',
                            'col' => 3,
                        ),
                    ),
                    'submit' => array(
                        'title' => $this->l('Save'),
                        'name' => 'submitQlofdSettings',
                    ),
                ),
            ),
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Rate Parity'),
                        'icon' => 'icon-balance-scale',
                    ),
                    'input' => array(
                        array(
                            'type' => 'text',
                            'label' => $this->l('Margin (%)'),
                            'name' => 'QLOFD_PARITY_MARGIN_PCT',
                            'col' => 3,
                            'desc' => $this->l('Own rate is flagged as not competitive when it exceeds the OTA rate by this margin.'),
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Commission range (min)'),
                            'name' => 'QLOFD_PARITY_COMMISSION_MIN',
                            'col' => 3,
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Commission range (max)'),
                            'name' => 'QLOFD_PARITY_COMMISSION_MAX',
                            'col' => 3,
                        ),
                    ),
                    'submit' => array(
                        'title' => $this->l('Save'),
                        'name' => 'submitQlofdSettings',
                    ),
                ),
            ),
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('No-Show Risk'),
                        'icon' => 'icon-eye-slash',
                    ),
                    'input' => array(
                        array('type' => 'text', 'label' => $this->l('Lead time weight'), 'name' => 'QLOFD_NO_SHOW_LEAD_TIME_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('No payment weight'), 'name' => 'QLOFD_NO_SHOW_NO_PAYMENT_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('Channel weight'), 'name' => 'QLOFD_NO_SHOW_CHANNEL_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('First-time guest weight'), 'name' => 'QLOFD_NO_SHOW_FIRST_TIME_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('Same-day booking weight'), 'name' => 'QLOFD_NO_SHOW_SAME_DAY_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('Group size weight'), 'name' => 'QLOFD_NO_SHOW_GROUP_W', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('Low threshold'), 'name' => 'QLOFD_NO_SHOW_LOW_THRESHOLD', 'col' => 3),
                        array('type' => 'text', 'label' => $this->l('High threshold'), 'name' => 'QLOFD_NO_SHOW_HIGH_THRESHOLD', 'col' => 3),
                    ),
                    'submit' => array(
                        'title' => $this->l('Save'),
                        'name' => 'submitQlofdSettings',
                    ),
                ),
            ),
        );
    }

    protected function renderCsvImportPanel()
    {
        $formAction = self::$currentIndex.'&token='.Tools::getAdminTokenLite($this->controller_name);

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-upload"></i> '.$this->l('OTA Rate Import').'</div>';
        $html .= '<div class="panel-body">';
        $html .= '<form method="post" action="'.Tools::safeOutput($formAction).'" enctype="multipart/form-data" class="form-horizontal">';
        $html .= '<div class="form-group">
                    <label class="control-label col-lg-3">'. $this->l('CSV file').'</label>
                    <div class="col-lg-6">
                        <input type="file" name="ota_csv" class="form-control" accept=".csv">
                        <span class="help-block">'.$this->l('Columns: id_hotel, id_product, date, currency, ota_name, rate, commission_pct').'</span>
                    </div>
                  </div>';
        $html .= '<div class="form-group">
                    <div class="col-lg-9 col-lg-offset-3">
                        <div class="checkbox"><label><input type="checkbox" name="replace_rates" value="1"> '.$this->l('Replace existing rates').'</label></div>
                    </div>
                  </div>';
        $html .= '<div class="form-group">
                    <div class="col-lg-9 col-lg-offset-3">
                        <button type="submit" name="submitQlofdImportOta" class="btn btn-primary"><i class="icon-upload"></i> '.$this->l('Import').'</button>
                        <button type="submit" name="submitQlofdRunParity" class="btn btn-default"><i class="icon-refresh"></i> '.$this->l('Run parity check now').'</button>
                    </div>
                  </div>';
        $html .= '</form></div></div>';

        return $html;
    }

    protected function renderParityAlerts()
    {
        $alerts = Db::getInstance()->executeS(
            'SELECT `pa`.*, `pl`.`name` AS `room_type_name`, `hbil`.`hotel_name`,
                    `c`.`sign` AS `currency_sign`
             FROM `'._DB_PREFIX_.'qlofd_parity_alert` `pa`
             LEFT JOIN `'._DB_PREFIX_.'product_lang` `pl`
                ON (`pl`.`id_product` = `pa`.`id_product` AND `pl`.`id_lang` = '.(int) Configuration::get('PS_LANG_DEFAULT').')
             LEFT JOIN `'._DB_PREFIX_.'htl_branch_info_lang` `hbil`
                ON (`hbil`.`id` = `pa`.`id_hotel` AND `hbil`.`id_lang` = '.(int) Configuration::get('PS_LANG_DEFAULT').')
             LEFT JOIN `'._DB_PREFIX_.'currency` `c` ON (`c`.`id_currency` = '.(int) Configuration::get('PS_CURRENCY_DEFAULT').')
             ORDER BY `pa`.`resolved` ASC, `pa`.`date` DESC
             LIMIT 100'
        );

        $formAction = self::$currentIndex.'&token='.Tools::getAdminTokenLite($this->controller_name);

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-bell"></i> '.$this->l('Parity Alerts').'</div>';
        $html .= '<div class="panel-body table-responsive"><table class="table table-striped">';
        $html .= '<thead><tr>
                    <th>'.$this->l('Hotel').'</th>
                    <th>'.$this->l('Room type').'</th>
                    <th>'.$this->l('Date').'</th>
                    <th>'.$this->l('OTA').'</th>
                    <th>'.$this->l('Issue').'</th>
                    <th class="text-right">'.$this->l('Own').'</th>
                    <th class="text-right">'.$this->l('OTA').'</th>
                    <th></th>
                  </tr></thead><tbody>';

        if ($alerts) {
            foreach ($alerts as $alert) {
                $sign = $alert['currency_sign'];
                $html .= '<tr'.($alert['resolved'] ? ' class="text-muted"' : '').'>';
                $html .= '<td>'.Tools::safeOutput($alert['hotel_name']).'</td>';
                $html .= '<td>'.Tools::safeOutput($alert['room_type_name']).'</td>';
                $html .= '<td>'.Tools::safeOutput($alert['date']).'</td>';
                $html .= '<td>'.Tools::safeOutput($alert['ota_name']).'</td>';
                $issue = $alert['issue_type'] === 'direct_not_competitive'
                    ? $this->l('Direct not competitive')
                    : $this->l('OTA more profitable');
                $html .= '<td>'.Tools::safeOutput($issue).'</td>';
                $html .= '<td class="text-right">'.Tools::safeOutput(number_format((float) $alert['own_rate'], 2)).' '.Tools::safeOutput($sign).'</td>';
                $html .= '<td class="text-right">'.Tools::safeOutput(number_format((float) $alert['ota_rate'], 2)).' '.Tools::safeOutput($sign).'</td>';
                $html .= '<td class="text-center">
                    <form method="post" action="'.Tools::safeOutput($formAction).'" class="no-style">
                        <input type="hidden" name="id_alert" value="'.(int) $alert['id'].'">
                        <button type="submit" name="submitQlofdResolveAlert" value="1" class="btn btn-xs '.($alert['resolved'] ? 'btn-default' : 'btn-success').'">
                            <input type="hidden" name="resolved" value="'.($alert['resolved'] ? 0 : 1).'">
                            '.($alert['resolved'] ? $this->l('Reopen') : $this->l('Resolve')).'
                        </button>
                    </form>
                  </td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="8" class="text-center">'.$this->l('No alerts yet. Import OTA rates to run parity checks.').'</td></tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }

    protected function renderAuditLog()
    {
        $logs = Db::getInstance()->executeS(
            'SELECT `al`.*, `e`.`firstname`, `e`.`lastname`
             FROM `'._DB_PREFIX_.'qlofd_audit_log` `al`
             LEFT JOIN `'._DB_PREFIX_.'employee` `e` ON (`e`.`id_employee` = `al`.`id_employee`)
             ORDER BY `al`.`id` DESC
             LIMIT 100'
        );

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list-alt"></i> '.$this->l('Audit Log').'</div>';
        $html .= '<div class="panel-body table-responsive"><table class="table table-condensed">';
        $html .= '<thead><tr>
                    <th>'.$this->l('Date').'</th>
                    <th>'.$this->l('Entity').'</th>
                    <th>'.$this->l('Action').'</th>
                    <th>'.$this->l('Employee').'</th>
                  </tr></thead><tbody>';

        if ($logs) {
            foreach ($logs as $log) {
                $html .= '<tr>';
                $html .= '<td>'.Tools::safeOutput($log['date_add']).'</td>';
                $html .= '<td>'.Tools::safeOutput($log['entity_type']).' #'.(int) $log['entity_id'].'</td>';
                $html .= '<td>'.Tools::safeOutput($log['action']).'</td>';
                $html .= '<td>'.Tools::safeOutput(trim($log['firstname'].' '.$log['lastname'])).'</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="4" class="text-center">'.$this->l('No audit entries yet.').'</td></tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }
}