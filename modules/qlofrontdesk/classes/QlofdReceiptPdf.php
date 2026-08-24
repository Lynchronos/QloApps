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
 * Folio receipt PDF generator. Renders through PDFGenerator (TCPDF),
 * following the HTMLTemplate pattern with a self-contained layout.
 */
class QlofdReceiptPdf extends HTMLTemplate
{
    /** @var array */
    public $folioData;

    public function __construct($folioData, $smarty)
    {
        $this->title = 'Receipt';
        $this->date = date('Y-m-d');
        $this->folioData = $folioData;

        $context = Context::getContext();
        $this->smarty = $smarty;
        $this->shop = $context->shop;
    }

    /**
     * @return string
     */
    public function getHeader()
    {
        $shopName = Configuration::get('PS_SHOP_NAME', null, null, (int) $this->shop->id);
        $html = '<div style="margin-bottom: 10px; padding-bottom: 6px; border-bottom: 2px solid #333;">';
        $html .= '<table style="width:100%;"><tr><td style="font-size:16px;font-weight:bold;">'.Tools::safeOutput($shopName).'</td>';
        $html .= '<td style="text-align:right;font-size:11px;color:#555;">'.Tools::safeOutput($this->title).'<br>'.Tools::safeOutput($this->date).'</td></tr></table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $data = $this->folioData;
        if (empty($data)) {
            return '<p>No folio data.</p>';
        }

        $sign = isset($data['currency_sign']) ? $data['currency_sign'] : '';

        $html = '<h3 style="margin:0 0 6px 0;">'.Tools::safeOutput(trim($data['customer'])).'</h3>';
        $html .= '<p style="margin:0 0 12px 0;font-size:11px;color:#555;">';
        $html .= Tools::safeOutput($data['room_num']).' — '.Tools::safeOutput($data['room_type_name']);
        $html .= '<br>'.date('Y-m-d', strtotime($data['date_from'])).' → '.date('Y-m-d', strtotime($data['date_to']));
        $html .= '</p>';

        $html .= '<table style="width:100%;border-collapse:collapse;font-size:11px;">';
        $html .= '<tr style="background:#eee;"><th style="text-align:left;">Item</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Total</th></tr>';

        $rows = array();
        $rows[] = array('label' => 'Room charge', 'qty' => 1, 'unit' => (float) $data['room_charge_tax_incl'], 'total' => (float) $data['room_charge_tax_incl']);

        foreach ($data['demands'] as $demand) {
            $rows[] = array('label' => $demand['label'], 'qty' => 1, 'unit' => (float) $demand['unit_price_tax_incl'], 'total' => (float) $demand['unit_price_tax_incl']);
        }

        foreach ($data['services'] as $service) {
            $rows[] = array('label' => $service['label'], 'qty' => (int) $service['qty'], 'unit' => (float) $service['unit_price_tax_incl'], 'total' => (float) $service['unit_price_tax_incl'] * (int) $service['qty']);
        }

        foreach ($data['folio_lines'] as $line) {
            $lineTotal = (float) $line['unit_price_tax_incl'] * (int) $line['qty'];
            if ($line['type'] === 'credit') {
                $lineTotal = -$lineTotal;
            }
            $rows[] = array('label' => $line['label'], 'qty' => (int) $line['qty'], 'unit' => (float) $line['unit_price_tax_incl'], 'total' => $lineTotal);
        }

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>'.Tools::safeOutput($row['label']).'</td>';
            $html .= '<td style="text-align:right;">'.(int) $row['qty'].'</td>';
            $html .= '<td style="text-align:right;">'.Tools::displayPrice($row['unit'], $data['id_currency']).'</td>';
            $html .= '<td style="text-align:right;">'.Tools::displayPrice($row['total'], $data['id_currency']).'</td>';
            $html .= '</tr>';
        }

        $html .= '<tr><td colspan="3" style="text-align:right;font-weight:bold;">Total charges</td>';
        $html .= '<td style="text-align:right;font-weight:bold;">'.Tools::displayPrice((float) $data['charges_tax_incl'], $data['id_currency']).'</td></tr>';

        if (!empty($data['payments'])) {
            foreach ($data['payments'] as $payment) {
                $html .= '<tr><td colspan="3" style="text-align:right;">Paid ('.Tools::safeOutput($payment['payment_method']).')</td>';
                $html .= '<td style="text-align:right;">-'.Tools::displayPrice((float) $payment['amount'], $data['id_currency']).'</td></tr>';
            }
        }
        $html .= '<tr><td colspan="3"></td></tr>';
        $html .= '<tr><td colspan="3" style="text-align:right;font-weight:bold;border-top:2px solid #333;">Balance due</td>';
        $html .= '<td style="text-align:right;font-weight:bold;border-top:2px solid #333;">'.Tools::displayPrice((float) $data['balance'], $data['id_currency']).'</td></tr>';
        $html .= '</table>';

        return $html;
    }

    /**
     * @return string
     */
    public function getFooter()
    {
        return '<div style="margin-top:14px;padding-top:6px;border-top:1px solid #999;font-size:9px;color:#777;text-align:center;">'.
            Tools::safeOutput(Configuration::get('PS_SHOP_NAME', null, null, (int) $this->shop->id)).
            ' — Generated by Front Desk'
            .'</div>';
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return 'receipt-'.$this->folioData['id_htl_booking'].'.pdf';
    }

    /**
     * @return string
     */
    public function getBulkFilename()
    {
        return 'receipts.pdf';
    }

    /**
     * Render and output the PDF for download.
     *
     * @return void
     */
    public function renderPdfAndOutput()
    {
        $pdf = new PDFGenerator(false, 'P');
        $pdf->setFontForLang(Context::getContext()->language->iso_code);

        $pdf->createHeader($this->getHeader());
        $pdf->createFooter($this->getFooter());
        $pdf->createPagination($this->getPagination());
        $pdf->createContent($this->getContent());
        $pdf->writePage();
        $pdf->render($this->getFilename(), true);
    }
}