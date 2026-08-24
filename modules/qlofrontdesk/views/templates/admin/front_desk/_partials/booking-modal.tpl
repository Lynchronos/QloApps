{* New booking modal - rendered via ajaxProcessGetBookingModalData. *}
{assign var='currencySign' value=null}
{if isset($currency_sign)}
	{assign var='currencySign' value=$currency_sign}
{elseif Configuration::get('PS_CURRENCY_DEFAULT')}
	{capture assign='currencySign'}{Currency::getCurrentInstance()->sign}{/capture}
{/if}
<form id="qlofd-booking-form" class="form-horizontal">
	<input type="hidden" name="id_hotel" value="{$id_hotel|intval}">

	<ul class="nav nav-tabs" role="tablist">
		<li class="active"><a href="#qlofd-tab-existing" role="tab" data-toggle="tab">{l s='Existing Guest' mod='qlofrontdesk'}</a></li>
		<li><a href="#qlofd-tab-new" role="tab" data-toggle="tab">{l s='New Guest' mod='qlofrontdesk'}</a></li>
	</ul>

	<div class="tab-content" style="padding-top:12px;">
		<div role="tabpanel" class="tab-pane active" id="qlofd-tab-existing">
			<div class="form-group">
				<div class="col-sm-12">
					<input type="text" id="qlofd-guest-search" class="form-control" autocomplete="off"
						   placeholder="{l s='Search by name or email...' mod='qlofrontdesk'}">
					<input type="hidden" name="id_customer" id="qlofd-id-customer" value="0">
					<div id="qlofd-guest-results" class="qlofd-guest-results"></div>
				</div>
			</div>
			<div id="qlofd-guest-selected" class="alert alert-success" style="display:none;"></div>
		</div>
		<div role="tabpanel" class="tab-pane" id="qlofd-tab-new">
			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class="control-label col-sm-4">{l s='First name' mod='qlofrontdesk'}</label>
						<div class="col-sm-8">
							<input type="text" name="firstname" class="form-control">
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class="control-label col-sm-4">{l s='Last name' mod='qlofrontdesk'}</label>
						<div class="col-sm-8">
							<input type="text" name="lastname" class="form-control">
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class="control-label col-sm-4">{l s='Email' mod='qlofrontdesk'}</label>
						<div class="col-sm-8">
							<input type="text" name="email" class="form-control">
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class="control-label col-sm-4">{l s='Phone' mod='qlofrontdesk'}</label>
						<div class="col-sm-8">
							<input type="text" name="phone" class="form-control">
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<hr>

	<div class="row">
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Check-In' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="text" id="qlofd-new-date-from" class="form-control" readonly value="">
					<input type="hidden" name="date_from" id="qlofd-new-date-from-iso" value="">
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Check-Out' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="text" id="qlofd-new-date-to" class="form-control" readonly value="">
					<input type="hidden" name="date_to" id="qlofd-new-date-to-iso" value="">
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Room Type' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<select name="id_product" id="qlofd-new-room-type" class="form-control">
						<option value="0">{l s='Select room type' mod='qlofrontdesk'}</option>
						{foreach $room_types as $rtype}
							<option value="{$rtype.id_product|intval}">{$rtype.room_type|escape:'htmlall':'UTF-8'}</option>
						{/foreach}
					</select>
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Room' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<select name="id_room" id="qlofd-new-room" class="form-control">
						<option value="0">{l s='Select room' mod='qlofrontdesk'}</option>
					</select>
					{if $suggested_room}
						<span class="help-block">
							<i class="icon-magic"></i>
							{l s='Suggested:' mod='qlofrontdesk'}
							<a href="#" class="qlofd-pick-suggested" data-room="{$suggested_room.id_room|intval}">
								{$suggested_room.room_num|escape:'htmlall':'UTF-8'}
							</a>
						</span>
					{/if}
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Adults' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="number" name="adults" class="form-control" value="2" min="1" max="9">
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Children' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="number" name="children" class="form-control" value="0" min="0" max="9">
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Total' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<p class="form-control-static qlofd-new-total">
						{if $total_price}{$total_price.total_price_tax_incl|floatval} {$currencySign}{/if}
					</p>
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Deposit' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="text" name="deposit" id="qlofd-new-deposit" class="form-control">
					<span class="help-block">{l s='Leave empty to collect full amount at once.' mod='qlofrontdesk'}</span>
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Payment Method' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="text" name="payment_method" class="form-control" value="{l s='Pay at Hotel' mod='qlofrontdesk'}">
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="form-group">
				<label class="control-label col-sm-4">{l s='Reference' mod='qlofrontdesk'}</label>
				<div class="col-sm-8">
					<input type="text" name="reference" class="form-control">
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-sm-12">
			<div class="form-group">
				<label class="control-label col-sm-2">{l s='Note' mod='qlofrontdesk'}</label>
				<div class="col-sm-10">
					<textarea name="comment" rows="2" class="form-control"></textarea>
				</div>
			</div>
		</div>
	</div>

	<div class="checkbox">
		<label>
			<input type="checkbox" name="is_group_booking" value="1">
			{l s='Part of a group booking' mod='qlofrontdesk'}
		</label>
	</div>

	<div class="form-group">
		<div class="col-sm-12">
			<button type="submit" class="btn btn-primary pull-right">
				<i class="icon-save"></i> {l s='Save Booking' mod='qlofrontdesk'}
			</button>
		</div>
	</div>
</form>

<script type="application/json" id="qlofd-available-types">{$available_types_json nofilter}</script>