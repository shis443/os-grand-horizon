<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Extra Money Notice Fees', 'Cargos Extra') ?> <span style="color:var(--pn-text-muted);font-size:14px;">(<?= $t('Open total', 'Total abierto') ?>: <?= htmlspecialchars($total_open) ?>)</span></h2>

<div class="pn-card">
	<h3><?= $t('Log a new charge', 'Registrar un nuevo cargo') ?></h3>
	<form id="pn-add-surcharge" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
		<div class="pn-field"><label><?= $t('Room', 'Habitación') ?></label><input class="pn-input" name="room" required></div>
		<div class="pn-field"><label><?= $t('Guest', 'Huésped') ?></label><input class="pn-input" name="guest_name"></div>
		<div class="pn-field"><label><?= $t('Date', 'Fecha') ?></label><input class="pn-input" type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
		<div class="pn-field"><label><?= $t('Amount (EUR)', 'Monto (EUR)') ?></label><input class="pn-input" type="number" step="0.01" min="0.01" name="amount_eur" required></div>
		<div class="pn-field"><label><?= $t('Reason', 'Motivo') ?></label><input class="pn-input" name="reason" placeholder="minibar, damages, pets…"></div>
		<button class="pn-btn" type="submit"><?= $t('Add Charge', 'Agregar Cargo') ?></button>
	</form>
</div>

<div class="pn-card">
	<form method="get" style="margin-bottom:10px;">
		<input class="pn-input pn-search" name="q" placeholder="<?= $t('Search guest or room…', 'Buscar huésped o habitación…') ?>" value="<?= htmlspecialchars($search ?: '') ?>">
		<button class="pn-btn pn-btn-secondary pn-btn-sm" type="submit"><?= $t('Search', 'Buscar') ?></button>
	</form>

	<?php if (empty($surcharges)): ?>
		<div class="pn-empty"><?= $t('No surcharges yet.', 'Aún no hay cargos.') ?></div>
	<?php else: ?>
	<table class="pn-table">
		<thead><tr>
			<th><?= $t('Date', 'Fecha') ?></th><th><?= $t('Room', 'Habitación') ?></th><th><?= $t('Guest', 'Huésped') ?></th>
			<th><?= $t('Reason', 'Motivo') ?></th><th><?= $t('Amount', 'Monto') ?></th><th><?= $t('Status', 'Estado') ?></th><th></th>
		</tr></thead>
		<tbody>
		<?php foreach ($surcharges as $s): ?>
			<tr>
				<td><?= htmlspecialchars($s['date']) ?></td>
				<td><?= htmlspecialchars($s['room']) ?></td>
				<td><?= htmlspecialchars($s['guest_name']) ?></td>
				<td><?= htmlspecialchars($s['reason']) ?></td>
				<td><?= panther_eur($s['amount_eur']) ?></td>
				<td>
					<?php if ($s['cleared_bool']): ?>
						<span class="pn-status-pill pn-status-checkin"><?= $t('Cleared', 'Liquidado') ?></span>
					<?php else: ?>
						<span class="pn-status-pill pn-status-checkout"><?= $t('Open', 'Abierto') ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if (!$s['cleared_bool']): ?>
						<button class="pn-btn pn-btn-sm pn-clear-surcharge" data-id="<?= (int) $s['id'] ?>"><?= $t('Clear Fee', 'Liquidar') ?></button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<script>
var base = document.getElementById('pn-base-url').value;

document.getElementById('pn-add-surcharge').addEventListener('submit', function (e) {
	e.preventDefault();
	jQuery.post(base + 'panther_surcharges/log', jQuery(this).serialize(), function (resp) {
		if (resp.success) location.reload(); else alert(resp.message);
	}, 'json');
});

document.querySelectorAll('.pn-clear-surcharge').forEach(function (btn) {
	btn.addEventListener('click', function () {
		jQuery.post(base + 'panther_surcharges/clear/' + btn.getAttribute('data-id'), {}, function (resp) {
			if (resp.success) location.reload();
		}, 'json');
	});
});
</script>
