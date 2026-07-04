<?php
$t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; };

$status_labels = array(
	'checkin'  => $t('Check-In', 'Entrada'),
	'reserved' => $t('Reserved', 'Reservado'),
	'checkout' => $t('Check-Out', 'Salida'),
);
$status_class = array(
	'checkin'  => 'pn-status-checkin',
	'reserved' => 'pn-status-reserved',
	'checkout' => 'pn-status-checkout',
);
$search_lc = $search ? mb_strtolower($search) : '';
?>
<h2><?= $t('Reservation Grid', 'Calendario de Reservas') ?> — <?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></h2>

<div class="pn-card">
	<form method="get" style="display:flex;gap:8px;">
		<input class="pn-input pn-search" name="q" placeholder="<?= $t('Search guest name…', 'Buscar por nombre de huésped…') ?>" value="<?= htmlspecialchars($search ?: '') ?>">
		<button class="pn-btn pn-btn-secondary pn-btn-sm" type="submit"><?= $t('Search', 'Buscar') ?></button>
	</form>
</div>

<div class="pn-card" style="overflow-x:auto;">
<table class="pn-grid-table">
	<thead>
		<tr>
			<th class="pn-grid-day"><?= $t('Day', 'Día') ?></th>
			<?php foreach ($rooms as $room): ?>
				<th><?= htmlspecialchars($room['room_name']) ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
	<?php for ($day = 1; $day <= $days_in_month; $day++): ?>
		<tr>
			<td class="pn-grid-day"><?= $day ?></td>
			<?php foreach ($rooms as $room):
				$entries = isset($cell_map[$room['room_id']][$day]) ? $cell_map[$room['room_id']][$day] : array();
			?>
				<td class="pn-cell">
				<?php foreach ($entries as $entry):
					$is_match = $search_lc && $entry['customer_name'] && strpos(mb_strtolower($entry['customer_name']), $search_lc) !== false;
					$status_key = !empty($entry['is_turnover']) ? null : $entry['cell_status'];
					$badge_cents = isset($surcharge_totals[$entry['booking_id']]) ? $surcharge_totals[$entry['booking_id']] : 0;
					$guest_label = $entry['customer_name']
						? $entry['customer_name'] . ' (' . panther_booking_source_label($entry['source'], $this->db) . ')'
						: $t('Guest', 'Huésped');
				?>
					<div class="pn-cell-entry" data-brh-id="<?= (int) $entry['booking_room_history_id'] ?>"
						 data-booking-id="<?= (int) $entry['booking_id'] ?>"
						 data-room-id="<?= (int) $room['room_id'] ?>"
						 data-room-name="<?= htmlspecialchars($room['room_name']) ?>"
						 data-date="<?= sprintf('%04d-%02d-%02d', $year, $month, $day) ?>"
						 data-checkin-time="<?= date('H:i', strtotime($entry['check_in_date'])) ?>"
						 style="<?= $is_match ? 'outline:2px solid var(--pn-accent);' : '' ?> margin-bottom:4px;">
						<?php if (!empty($entry['is_turnover'])): ?>
							<div class="pn-cell-status pn-status-pill pn-status-checkout"><?= $t('Check-Out & Check-In', 'Salida y Entrada') ?></div>
						<?php else: ?>
							<div class="pn-cell-status pn-status-pill <?= $status_class[$entry['cell_status']] ?>"><?= $status_labels[$entry['cell_status']] ?></div>
						<?php endif; ?>
						<div class="pn-cell-guest"><?= htmlspecialchars($guest_label) ?></div>
						<?php if ($entry['cell_status'] !== 'checkout'): ?>
							<div class="pn-cell-time"><?= date('H:i', strtotime($entry['check_in_date'])) ?></div>
						<?php endif; ?>
						<?php if ($badge_cents > 0): ?>
							<div class="pn-cell-badge">+<?= panther_eur($badge_cents) ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</td>
			<?php endforeach; ?>
		</tr>
	<?php endfor; ?>
	</tbody>
</table>
</div>

<div class="pn-modal-backdrop" id="pn-cell-modal">
	<div class="pn-modal">
		<h4><?= $t('Edit Reservation Cell', 'Editar Celda de Reserva') ?></h4>
		<div class="pn-field"><label><?= $t('Check-in time', 'Hora de entrada') ?></label><input type="time" id="pn-modal-checkin-time" class="pn-input"></div>
		<div class="pn-field"><label><?= $t('Log extra money (EUR)', 'Registrar dinero extra (EUR)') ?></label>
			<div style="display:flex;gap:6px;">
				<input type="number" step="0.01" min="0.01" id="pn-modal-amount" class="pn-input" style="flex:1;" placeholder="0.00">
				<input type="text" id="pn-modal-reason" class="pn-input" style="flex:1;" placeholder="<?= $t('reason', 'motivo') ?>">
			</div>
		</div>
		<div class="pn-field">
			<label><?= $t('Cleaning', 'Limpieza') ?></label>
			<button type="button" class="pn-btn pn-btn-secondary" id="pn-modal-toggle-cleaning"><?= $t('Toggle Cleaning Request', 'Alternar Solicitud de Limpieza') ?></button>
		</div>
		<div class="pn-modal-actions">
			<button type="button" class="pn-btn pn-btn-secondary" id="pn-modal-cancel"><?= $t('Cancel', 'Cancelar') ?></button>
			<button type="button" class="pn-btn" id="pn-modal-save"><?= $t('Save', 'Guardar') ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	var base = document.getElementById('pn-base-url').value;
	var modal = document.getElementById('pn-cell-modal');
	var current = null;

	document.querySelectorAll('.pn-cell-entry').forEach(function (el) {
		el.addEventListener('click', function () {
			current = {
				brh_id: el.getAttribute('data-brh-id'),
				booking_id: el.getAttribute('data-booking-id'),
				room_id: el.getAttribute('data-room-id'),
				room_name: el.getAttribute('data-room-name'),
				date: el.getAttribute('data-date')
			};
			document.getElementById('pn-modal-checkin-time').value = el.getAttribute('data-checkin-time');
			document.getElementById('pn-modal-amount').value = '';
			document.getElementById('pn-modal-reason').value = '';
			modal.classList.add('open');
		});
	});

	document.getElementById('pn-modal-cancel').addEventListener('click', function () { modal.classList.remove('open'); });

	document.getElementById('pn-modal-save').addEventListener('click', function () {
		var time = document.getElementById('pn-modal-checkin-time').value;
		var amount = document.getElementById('pn-modal-amount').value;
		var reason = document.getElementById('pn-modal-reason').value;
		var tasks = [];

		if (time) {
			tasks.push(jQuery.post(base + 'panther_grid/update_check_in_time', {
				booking_room_history_id: current.brh_id, time: time, room_name: current.room_name
			}));
		}
		if (amount && parseFloat(amount) > 0) {
			tasks.push(jQuery.post(base + 'panther_surcharges/log', {
				booking_id: current.booking_id, room: current.room_name, date: current.date,
				amount_eur: amount, reason: reason
			}));
		}
		jQuery.when.apply(jQuery, tasks).always(function () { location.reload(); });
	});

	document.getElementById('pn-modal-toggle-cleaning').addEventListener('click', function () {
		jQuery.post(base + 'panther_grid/toggle_cleaning', {
			room_id: current.room_id, booking_id: current.booking_id, date: current.date, room_name: current.room_name
		}, function () { location.reload(); }, 'json');
	});
})();
</script>
