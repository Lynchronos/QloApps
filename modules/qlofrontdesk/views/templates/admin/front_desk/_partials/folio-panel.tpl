{* Folio panel - rendered via ajaxProcessGetFolio (and refresh actions). *}
<div class="qlofd-folio">
	<div class="modal-header">
		<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
		<h4 class="modal-title">
			<i class="icon-file-text"></i> {l s='Folio' mod='qlofrontdesk'} — {$folio.customer|escape:'htmlall':'UTF-8'}
			<span class="label {if $folio.payment_status == 'paid'}label-success{elseif $folio.payment_status == 'partial'}label-warning{else}label-default{/if} pull-right">
				{$folio.payment_status|escape:'htmlall':'UTF-8'}
			</span>
		</h4>
	</div>
	<div class="modal-body">
		<div class="row qlofd-folio-meta">
			<div class="col-md-6">
				<strong>{l s='Room' mod='qlofrontdesk'}:</strong> {$folio.room_num|escape:'htmlall':'UTF-8'} ({$folio.room_type_name|escape:'htmlall':'UTF-8'})
			</div>
			<div class="col-md-6">
				<strong>{l s='Stay' mod='qlofrontdesk'}:</strong> {$folio.date_from|date_format:"%d-%m-%Y"} → {$folio.date_to|date_format:"%d-%m-%Y"}
			</div>
		</div>

		<div class="table-responsive">
			<table class="table table-striped qlofd-folio-lines">
				<thead>
					<tr>
						<th>{l s='Item' mod='qlofrontdesk'}</th>
						<th class="text-center" style="width:60px;">{l s='Qty' mod='qlofrontdesk'}</th>
						<th class="text-right" style="width:110px;">{l s='Price' mod='qlofrontdesk'}</th>
						<th class="text-right" style="width:120px;">{l s='Total' mod='qlofrontdesk'}</th>
						<th style="width:50px;"></th>
					</tr>
				</thead>
				<tbody>
					<tr class="qlofd-folio-room">
						<td>{l s='Room charge' mod='qlofrontdesk'}</td>
						<td class="text-center">1</td>
						<td class="text-right">{$folio.room_charge_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
						<td class="text-right">{$folio.room_charge_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
						<td></td>
					</tr>
					{foreach $folio.demands as $demand}
						<tr>
							<td>{$demand.label|escape:'htmlall':'UTF-8'}</td>
							<td class="text-center">{$demand.qty|intval}</td>
							<td class="text-right">{$demand.unit_price_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
							<td class="text-right">{$demand.unit_price_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
							<td></td>
						</tr>
					{/foreach}
					{foreach $folio.services as $service}
						<tr>
							<td>{$service.label|escape:'htmlall':'UTF-8'}</td>
							<td class="text-center">{$service.qty|intval}</td>
							<td class="text-right">{$service.unit_price_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
							<td class="text-right">{($service.unit_price_tax_incl * $service.qty)|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
							<td></td>
						</tr>
					{/foreach}
					{foreach $folio.folio_lines as $line}
						<tr class="qlofd-folio-line-row" data-line-id="{$line.id|intval}" data-type="{$line.type|escape:'htmlall':'UTF-8'}">
							<td class="qlofd-line-label">{$line.label|escape:'htmlall':'UTF-8'} {if $line.type == 'credit'}<span class="label label-danger">{l s='credit' mod='qlofrontdesk'}</span>{/if}</td>
							<td class="text-center qlofd-line-qty">{$line.qty|intval}</td>
							<td class="text-right qlofd-line-price">{$line.unit_price_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
							<td class="text-right qlofd-line-total">
								{if $line.type == 'credit'}-{/if}{($line.unit_price_tax_incl * $line.qty)|floatval|string_format:"%.2f"} {$folio.currency_sign}
							</td>
							<td class="text-center">
								<button type="button" class="btn btn-xs btn-default qlofd-line-edit" title="{l s='Edit' mod='qlofrontdesk'}"><i class="icon-pencil"></i></button>
								<button type="button" class="btn btn-xs btn-danger qlofd-line-delete" title="{l s='Delete' mod='qlofrontdesk'}"><i class="icon-trash"></i></button>
							</td>
						</tr>
					{/foreach}
					<tr class="qlofd-folio-add-line">
						<td>
							<input type="text" class="form-control input-sm qlofd-line-new-label" placeholder="{l s='Item (e.g. Mini bar)' mod='qlofrontdesk'}">
						</td>
						<td>
							<input type="number" class="form-control input-sm text-center qlofd-line-new-qty" value="1" min="1">
						</td>
						<td>
							<input type="text" class="form-control input-sm text-right qlofd-line-new-price" placeholder="0.00">
						</td>
						<td>
							<select class="form-control input-sm qlofd-line-new-type">
								<option value="charge">{l s='Charge' mod='qlofrontdesk'}</option>
								<option value="credit">{l s='Credit' mod='qlofrontdesk'}</option>
							</select>
						</td>
						<td class="text-center">
							<button type="button" class="btn btn-xs btn-success qlofd-line-add"><i class="icon-plus"></i></button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<hr>

		<div class="row">
			<div class="col-md-6">
				<h5>{l s='Payments' mod='qlofrontdesk'}</h5>
				{if $folio.payments|count}
					<table class="table table-condensed">
						<thead>
							<tr><th>{l s='Date' mod='qlofrontdesk'}</th><th>{l s='Method' mod='qlofrontdesk'}</th><th class="text-right">{l s='Amount' mod='qlofrontdesk'}</th></tr>
						</thead>
						<tbody>
							{foreach $folio.payments as $payment}
								<tr>
									<td>{$payment.date_add|date_format:"%d-%m-%Y %H:%M"}</td>
									<td>{$payment.payment_method|escape:'htmlall':'UTF-8'} {if $payment.reference}<em>({$payment.reference|escape:'htmlall':'UTF-8'})</em>{/if}</td>
									<td class="text-right">{$payment.amount|floatval|string_format:"%.2f"} {$folio.currency_sign}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				{else}
					<p class="text-muted">{l s='No payments recorded yet.' mod='qlofrontdesk'}</p>
				{/if}

				<div class="row qlofd-folio-add-payment">
					<div class="col-sm-4">
						<input type="text" class="form-control input-sm qlofd-pay-amount" placeholder="{l s='Amount' mod='qlofrontdesk'}">
					</div>
					<div class="col-sm-5">
						<input type="text" class="form-control input-sm qlofd-pay-method" placeholder="{l s='Method' mod='qlofrontdesk'}">
					</div>
					<div class="col-sm-3">
						<input type="text" class="form-control input-sm qlofd-pay-reference" placeholder="{l s='Reference' mod='qlofrontdesk'}">
					</div>
					<div class="col-sm-12" style="margin-top:6px;">
						<button type="button" class="btn btn-xs btn-primary qlofd-pay-add"><i class="icon-money"></i> {l s='Add Payment' mod='qlofrontdesk'}</button>
						<button type="button" class="btn btn-xs btn-info qlofd-receipt-print"><i class="icon-print"></i> {l s='Receipt' mod='qlofrontdesk'}</button>
					</div>
				</div>
			</div>

			<div class="col-md-6">
				<div class="panel panel-default">
					<div class="panel-body">
						<div class="row">
							<div class="col-xs-8">{l s='Total charges' mod='qlofrontdesk'}</div>
							<div class="col-xs-4 text-right">{$folio.charges_tax_incl|floatval|string_format:"%.2f"} {$folio.currency_sign}</div>
						</div>
						<div class="row">
							<div class="col-xs-8">{l s='Total paid' mod='qlofrontdesk'}</div>
							<div class="col-xs-4 text-right">{$folio.total_paid|floatval|string_format:"%.2f"} {$folio.currency_sign}</div>
						</div>
						<hr>
						<div class="row">
							<div class="col-xs-8"><strong>{l s='Balance due' mod='qlofrontdesk'}</strong></div>
							<div class="col-xs-4 text-right qlofd-folio-balance">
								<strong>{$folio.balance|floatval|string_format:"%.2f"} {$folio.currency_sign}</strong>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal-footer">
		<input type="hidden" class="qlofd-folio-booking-id" value="{$folio.id_htl_booking|intval}">
		<button type="button" class="btn btn-default" data-dismiss="modal">{l s='Close' mod='qlofrontdesk'}</button>
	</div>
</div>