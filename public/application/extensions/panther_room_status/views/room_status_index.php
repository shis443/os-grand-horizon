<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Live Room Status', 'Estado de Habitaciones en Vivo') ?></h2>

<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
	<div style="flex:2;min-width:320px;">
		<div class="pn-cards-row">
			<?php foreach ($rooms as $r): ?>
				<div class="pn-card" style="min-width:220px;">
					<h3><?= htmlspecialchars($r['room_name']) ?></h3>
					<?php if ($r['booking_id']): ?>
						<span class="pn-status-pill pn-status-occupied"><?= $t('Occupied', 'Ocupada') ?></span>
						<p style="margin-top:8px;"><?= htmlspecialchars($r['customer_name'] ?: $t('Guest', 'Huésped')) ?><br>
							<span style="color:var(--pn-text-muted);font-size:12px;"><?= panther_booking_source_label($r['source'], $this->db) ?></span>
						</p>
					<?php else: ?>
						<span class="pn-status-pill pn-status-vacant"><?= $t('Vacant', 'Vacante') ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if (!empty($arrivals)): ?>
		<div class="pn-card">
			<h3><?= $t('Arriving Today — Check-In Entry', 'Llegadas de Hoy — Registro de Entrada') ?></h3>
			<table class="pn-table">
				<thead><tr><th><?= $t('Room', 'Habitación') ?></th><th><?= $t('Guest', 'Huésped') ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ($arrivals as $a): ?>
					<tr>
						<td><?= htmlspecialchars($a['room_name']) ?></td>
						<td><?= htmlspecialchars($a['customer_name'] ?: '—') ?></td>
						<td><button class="pn-btn pn-btn-sm pn-check-in" data-booking-id="<?= (int) $a['booking_id'] ?>" data-room-name="<?= htmlspecialchars($a['room_name']) ?>"><?= $t('Check-In', 'Registrar Entrada') ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>

	<div style="flex:1;min-width:220px;">
		<div class="pn-card">
			<h3><?= $t('Operational Stats', 'Estadísticas Operativas') ?></h3>
			<p><?= $t('Vacant', 'Vacante') ?>: <strong><?= $stats['vacant'] ?></strong></p>
			<p><?= $t('Occupied', 'Ocupada') ?>: <strong><?= $stats['occupied'] ?></strong></p>
			<p><?= $t('Unpaid surcharges', 'Cargos extra pendientes') ?>: <strong><?= $unpaid_count ?></strong></p>
		</div>
		<div class="pn-card">
			<h3><?= $t('Live Date State', 'Fecha en Vivo') ?></h3>
			<p><?= htmlspecialchars($sim_date) ?></p>
		</div>
	</div>
</div>

<script>
document.querySelectorAll('.pn-check-in').forEach(function (btn) {
	btn.addEventListener('click', function () {
		jQuery.post(document.getElementById('pn-base-url').value + 'panther_room_status/check_in/' + btn.getAttribute('data-booking-id'),
			{ room_name: btn.getAttribute('data-room-name') },
			function (resp) { if (resp.success) location.reload(); }, 'json');
	});
});
</script>
