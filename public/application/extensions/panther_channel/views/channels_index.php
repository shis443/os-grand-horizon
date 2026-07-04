<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Channels', 'Canales') ?></h2>

<div class="pn-cards-row">
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t('Connection', 'Conexión') ?></div>
		<div class="pn-stat-value" style="font-size:18px;">
			<?php if (!$is_configured): ?>
				<span class="pn-status-pill pn-status-checkout"><?= $t('Not configured (.env)', 'No configurado (.env)') ?></span>
			<?php elseif ($ota_x_company && $ota_x_company['is_active']): ?>
				<span class="pn-status-pill pn-status-checkin"><?= $t('Connected', 'Conectado') ?></span>
			<?php else: ?>
				<span class="pn-status-pill pn-status-cleaning"><?= $t('Not connected yet', 'Aún no conectado') ?></span>
			<?php endif; ?>
		</div>
	</div>
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t('Pending pushes', 'Envíos pendientes') ?></div>
		<div class="pn-stat-value"><?= (int) $pending_push ?></div>
	</div>
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t('Failed syncs', 'Sincronizaciones fallidas') ?></div>
		<div class="pn-stat-value" style="<?= $failed_count > 0 ? 'color:var(--pn-red);' : '' ?>"><?= (int) $failed_count ?></div>
	</div>
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t('Last webhook received', 'Último webhook recibido') ?></div>
		<div class="pn-stat-value" style="font-size:16px;"><?= $last_webhook_at ? htmlspecialchars($last_webhook_at) : $t('never', 'nunca') ?></div>
	</div>
</div>

<div class="pn-card">
	<h3><?= $t('Actions', 'Acciones') ?></h3>
	<button id="pn-test-connection" class="pn-btn"><?= $t('Test Connection', 'Probar Conexión') ?></button>
	<button id="pn-pull-bookings" class="pn-btn pn-btn-secondary"><?= $t('Pull Future Bookings', 'Traer Reservas Futuras') ?></button>
	<button id="pn-full-sync" class="pn-btn pn-btn-secondary"><?= $t('Full Sync (365 days)', 'Sincronización Completa (365 días)') ?></button>
	<span id="pn-action-result" style="margin-left:10px;color:var(--pn-text-muted);"></span>
</div>

<div class="pn-card">
	<h3><?= $t('Cabin Mapping', 'Mapeo de Cabañas') ?></h3>
	<p style="color:var(--pn-text-muted);"><?= $t('Map each cabin to its Channex room_type_id (and optionally a rate_plan_id). Find these in your Channex dashboard under Room Types / Rate Plans.', 'Mapea cada cabaña a su room_type_id de Channex (y opcionalmente un rate_plan_id). Encuéntralos en tu panel de Channex en Tipos de Habitación / Planes de Tarifa.') ?></p>
	<table class="pn-table">
		<thead><tr><th><?= $t('Cabin', 'Cabaña') ?></th><th>Channex room_type_id</th><th>Channex rate_plan_id</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($room_mappings as $m): ?>
			<tr>
				<td><?= htmlspecialchars($m['room']['room_name']) ?></td>
				<td><input class="pn-input pn-mapping-room-type" data-room-id="<?= (int) $m['room']['room_id'] ?>" value="<?= htmlspecialchars($m['ota_room_type_id']) ?>" placeholder="uuid"></td>
				<td><input class="pn-input pn-mapping-rate-plan" data-room-id="<?= (int) $m['room']['room_id'] ?>" value="<?= htmlspecialchars($m['ota_rate_plan_id']) ?>" placeholder="uuid"></td>
				<td><button class="pn-btn pn-btn-sm pn-save-mapping" data-room-id="<?= (int) $m['room']['room_id'] ?>"><?= $t('Save', 'Guardar') ?></button></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="pn-card">
	<h3><?= $t('Reservations from channels', 'Reservas de canales') ?></h3>
	<?php if (empty($reservations)): ?>
		<div class="pn-empty"><?= $t('No channel reservations yet.', 'Aún no hay reservas de canales.') ?></div>
	<?php else: ?>
	<table class="pn-table">
		<thead><tr><th><?= $t('Channel', 'Canal') ?></th><th><?= $t('Guest', 'Huésped') ?></th><th><?= $t('Dates', 'Fechas') ?></th><th><?= $t('Status', 'Estado') ?></th><th><?= $t('Gross', 'Bruto') ?></th></tr></thead>
		<tbody>
		<?php foreach ($reservations as $r): ?>
			<tr>
				<td><?= htmlspecialchars($r['channel_code']) ?></td>
				<td><?= htmlspecialchars($r['guest_name']) ?></td>
				<td><?= htmlspecialchars($r['arrival_date']) ?> → <?= htmlspecialchars($r['departure_date']) ?></td>
				<td>
					<?php $pill = $r['status'] === 'cancelled' ? 'pn-status-checkout' : ($r['status'] === 'new' ? 'pn-status-checkin' : 'pn-status-reserved'); ?>
					<span class="pn-status-pill <?= $pill ?>"><?= htmlspecialchars($r['status']) ?></span>
				</td>
				<td><?= $r['gross_amount_cents'] !== null ? panther_eur($r['gross_amount_cents']) : '—' ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<div class="pn-card">
	<h3><?= $t('Error Feed', 'Registro de Errores') ?></h3>
	<?php $failed_logs = array_values(array_filter($sync_log, function ($l) { return $l['status'] === 'failed'; })); ?>
	<?php if (empty($failed_logs)): ?>
		<div class="pn-empty"><?= $t('No failures recorded.', 'No se registraron fallos.') ?></div>
	<?php else: ?>
	<table class="pn-table">
		<thead><tr><th><?= $t('When', 'Cuándo') ?></th><th><?= $t('Direction', 'Dirección') ?></th><th><?= $t('Operation', 'Operación') ?></th><th><?= $t('Error', 'Error') ?></th></tr></thead>
		<tbody>
		<?php foreach ($failed_logs as $log): ?>
			<tr>
				<td><?= htmlspecialchars($log['created_at']) ?></td>
				<td><?= htmlspecialchars($log['direction']) ?></td>
				<td><?= htmlspecialchars($log['operation']) ?></td>
				<td style="color:var(--pn-red);"><?= htmlspecialchars($log['error']) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<script>
var base = document.getElementById('pn-base-url').value;
var resultEl = document.getElementById('pn-action-result');

function runAction(url, label) {
	resultEl.textContent = label + '...';
	jQuery.post(url, {}, function (resp) {
		resultEl.textContent = resp.success ? (label + ': OK') : (label + ' failed: ' + (resp.message || resp.error || 'unknown error'));
		if (resp.success) setTimeout(function(){ location.reload(); }, 1200);
	}, 'json').fail(function () {
		resultEl.textContent = label + ': request failed';
	});
}

document.getElementById('pn-test-connection').addEventListener('click', function () {
	runAction(base + 'panther_channel/test_connection', '<?= $t('Test Connection', 'Probar Conexión') ?>');
});
document.getElementById('pn-pull-bookings').addEventListener('click', function () {
	runAction(base + 'panther_channel/pull_future_bookings', '<?= $t('Pull Future Bookings', 'Traer Reservas Futuras') ?>');
});
document.getElementById('pn-full-sync').addEventListener('click', function () {
	if (!confirm('<?= $t('Queue a full 365-day availability sync for all cabins?', '¿Encolar una sincronización completa de disponibilidad de 365 días para todas las cabañas?') ?>')) return;
	runAction(base + 'panther_channel/full_sync', '<?= $t('Full Sync', 'Sincronización Completa') ?>');
});

document.querySelectorAll('.pn-save-mapping').forEach(function (btn) {
	btn.addEventListener('click', function () {
		var roomId = btn.getAttribute('data-room-id');
		var roomType = document.querySelector('.pn-mapping-room-type[data-room-id="' + roomId + '"]').value;
		var ratePlan = document.querySelector('.pn-mapping-rate-plan[data-room-id="' + roomId + '"]').value;
		jQuery.post(base + 'panther_channel/save_mapping', {
			room_id: roomId, ota_room_type_id: roomType, ota_rate_plan_id: ratePlan
		}, function (resp) {
			alert(resp.success ? 'Saved.' : (resp.message || 'Failed'));
		}, 'json');
	});
});
</script>
