<div class="row">
	<div class="col-lg-12">
		<form class="panel qlofd-filters" id="qlofd-filters" method="get" action="">
			<div class="panel-body">
				<div class="row">
					<input type="hidden" name="controller" value="AdminFrontDesk">
					<input type="hidden" name="token" value="{$token}">
					<input type="hidden" name="date_from" id="qlofd-date-from-hidden" value="{$date_from|escape:'htmlall':'UTF-8'}" data-window-days="{$window_days|intval}">
					<div class="col-md-2">
						<label>{l s='Hotel' mod='qlofrontdesk'}</label>
						<select name="id_hotel" id="qlofd-hotel" class="form-control">
							{foreach $hotels as $hotel}
								<option value="{$hotel.id_hotel|intval}" {if $hotel.id_hotel == $id_hotel}selected{/if}>{$hotel.hotel_name|escape:'htmlall':'UTF-8'}</option>
							{foreachelse}
								<option value="0">{l s='No hotels' mod='qlofrontdesk'}</option>
							{/foreach}
						</select>
					</div>
					<div class="col-md-2">
						<label>{l s='Room Type' mod='qlofrontdesk'}</label>
						<select name="id_room_type" id="qlofd-room-type" class="form-control">
							<option value="0">{l s='All Types' mod='qlofrontdesk'}</option>
							{foreach $room_types as $rtype}
								<option value="{$rtype.id_product|intval}" {if $rtype.id_product == $id_room_type}selected{/if}>{$rtype.room_type|escape:'htmlall':'UTF-8'}</option>
							{/foreach}
						</select>
					</div>
					<div class="col-md-2">
						<label>{l s='From' mod='qlofrontdesk'}</label>
						<div class="input-group">
							<input type="text" id="qlofd-date-from" class="form-control" value="{$date_from|date_format:"%d-%m-%Y"}" readonly>
							<span class="input-group-addon"><i class="icon-calendar"></i></span>
						</div>
					</div>
					<div class="col-md-6 qlofd-filter-actions">
						<label class="d-block">&nbsp;</label>
						<div class="btn-group btn-group-sm">
							<button type="button" class="btn btn-default" id="qlofd-prev-window" title="{l s='Previous window' mod='qlofrontdesk'}"><i class="icon-chevron-left"></i></button>
							<button type="button" class="btn btn-default" id="qlofd-today">{l s='Today' mod='qlofrontdesk'}</button>
							<button type="button" class="btn btn-default" id="qlofd-next-window" title="{l s='Next window' mod='qlofrontdesk'}"><i class="icon-chevron-right"></i></button>
						</div>
						<button type="button" class="btn btn-primary" id="qlofd-apply-filters">{l s='Refresh' mod='qlofrontdesk'}
							<i class="icon-refresh"></i>
						</button>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="row">
	<div class="col-lg-12">
		<div class="panel">
			<div class="panel-heading">
				<i class="icon-calendar"></i> {l s='Room Timeline' mod='qlofrontdesk'}
				<div class="pull-right qlofd-legend">
					<span class="qlofd-legend-item qlofd-bar-alloted">&nbsp;{l s='Alloted' mod='qlofrontdesk'}</span>
					<span class="qlofd-legend-item qlofd-bar-checkin">&nbsp;{l s='Checked In' mod='qlofrontdesk'}</span>
					<span class="qlofd-legend-item qlofd-bar-checkout">&nbsp;{l s='Checked Out' mod='qlofrontdesk'}</span>
					<span class="qlofd-legend-item qlofd-ooo-cell">&nbsp;{l s='OOO' mod='qlofrontdesk'}</span>
					<span class="qlofd-legend-item qlofd-hk-dirty">&nbsp;{l s='Dirty' mod='qlofrontdesk'}</span>
				</div>
			</div>
			<div class="panel-body">
				{if $rooms|count}
					<div class="qlofd-grid">
						<div class="qlofd-grid-header">
							<div class="qlofd-col-label">
								<span class="qlofd-hk-header">{l s='HK' mod='qlofrontdesk'}</span> {l s='Room' mod='qlofrontdesk'}
							</div>
							<div class="qlofd-col-dates">
								{foreach $dates as $date}
									<div class="qlofd-date-cell header" data-date="{$date}">
										<span class="qlofd-date-dow">{$date|date_format:"%a"}</span>
										<span class="qlofd-date-dom">{$date|substr:8:2}</span>
									</div>
								{/foreach}
							</div>
						</div>
						{foreach $rooms as $room}
							<div class="qlofd-room-row {if $room.is_temp_inactive}qlofd-room-temp-inactive{/if}"
								 data-room-id="{$room.id|intval}"
								 data-room-num="{$room.room_num|escape:'htmlall':'UTF-8'}"
								 data-room-type="{$room.id_product|intval}"
								 data-hk="{$room.housekeeping|escape:'htmlall':'UTF-8'}">
								<div class="qlofd-col-label">
									<span class="qlofd-hk-badge {if $room.housekeeping}qlofd-hk-{$room.housekeeping|escape:'htmlall':'UTF-8'}{/if}"
										  data-room-id="{$room.id|intval}">
										{if $room.housekeeping}{$room.housekeeping|substr:0:1|upper}{else}–{/if}
									</span>
									<span class="qlofd-room-num">{$room.room_num|escape:'htmlall':'UTF-8'}</span>
									<span class="qlofd-room-type">{$room.room_type_name|escape:'htmlall':'UTF-8'}</span>
									{if $room.floor}<span class="qlofd-room-floor">F{$room.floor|escape:'htmlall':'UTF-8'}</span>{/if}
								</div>
								<div class="qlofd-room-timeline">
									<div class="qlofd-cells-layer">
										{foreach $dates as $date}
											<div class="qlofd-date-cell" data-date="{$date}"></div>
										{/foreach}
									</div>
									<div class="qlofd-ooo-layer">
										{if isset($ooo_layout[$room.id])}
											{foreach $ooo_layout[$room.id] as $ooo}
												<div class="qlofd-ooo-bar" style="left:{$ooo.left}%;width:{$ooo.width}%"
													 data-ooo-id="{$ooo.id|intval}" title="{$ooo.reason|escape:'htmlall':'UTF-8'}">
													<i class="icon-ban"></i>
												</div>
											{/foreach}
										{/if}
									</div>
									<div class="qlofd-bars-layer">
										{if isset($booking_bars[$room.id])}
											{foreach $booking_bars[$room.id] as $bar}
												<div class="qlofd-bar qlofd-bar-{$bar.status|intval} {if $bar.is_locked_assignment}qlofd-bar-locked{/if} {if $bar.is_no_show}qlofd-bar-noshow{/if}"
													 data-booking-id="{$bar.id_htl_booking|intval}"
													 data-room-id="{$bar.id_room|intval}"
													 data-room-type="{$bar.id_product|intval}"
													 data-date-from="{$bar.date_from}"
													 data-date-to="{$bar.date_to}"
													 data-status="{$bar.status|intval}"
													 title="{$bar.customer|escape:'htmlall':'UTF-8'} · {$bar.date_from} → {$bar.date_to}, {$bar.nights|intval} {l s='nights' mod='qlofrontdesk'}, {$bar.payment_status|escape:'htmlall':'UTF-8'}{if $bar.no_show_label} · risk:{$bar.no_show_label|escape:'htmlall':'UTF-8'}{/if}"
													 style="left:{$bar.left}%;width:{$bar.width}%;">
													<span class="qlofd-bar-name">{$bar.customer|escape:'htmlall':'UTF-8'}</span>
{if $bar.is_group_booking || $bar.group_overlap >= $group_threshold}
													<span class="qlofd-bar-group" title="{l s='Group booking' mod='qlofrontdesk'}">{$bar.group_overlap|intval}</span>
												{/if}
													{if $bar.no_show_label == 'high'}
														<span class="qlofd-bar-risk">{l s='risk' mod='qlofrontdesk'}</span>
													{/if}
													{if $bar.is_locked_assignment}<i class="qlofd-lock icon-lock"></i>{/if}
												</div>
											{/foreach}
										{/if}
									</div>
								</div>
							</div>
						{/foreach}
					</div>
				{else}
					<div class="alert alert-warning">
						<i class="icon-warning"></i> {l s='No rooms found for the selected filter.' mod='qlofrontdesk'}
					</div>
				{/if}
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="qlofd-booking-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				<h4 class="modal-title" id="qlofd-modal-title">{l s='Booking' mod='qlofrontdesk'}</h4>
			</div>
			<div class="modal-body" id="qlofd-modal-body">
				<div class="qlofd-modal-loading text-center">
					<i class="icon-spinner icon-spin"></i>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="qlofd-folio-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content" id="qlofd-folio-content"></div>
	</div>
</div>