<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Crew Cash Register', 'Caja Registradora') ?></h2>

<div class="pn-cards-row">
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t("What's In", 'Lo que Entró') ?></div>
		<div class="pn-stat-value" style="color:var(--pn-green);"><?= panther_eur($totals['in_cents']) ?></div>
	</div>
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t("What's Bought", 'Lo que se Compró') ?></div>
		<div class="pn-stat-value" style="color:var(--pn-red);"><?= panther_eur($totals['out_cents']) ?></div>
	</div>
	<div class="pn-card">
		<div class="pn-stat-label"><?= $t("What's Left", 'Lo que Queda') ?></div>
		<div class="pn-stat-value"><?= panther_eur($totals['balance_cents']) ?></div>
	</div>
</div>

<div class="pn-cards-row">
	<div class="pn-card">
		<h3><?= $t('Log Income', 'Registrar Ingreso') ?></h3>
		<form class="pn-cash-form" data-action="log_income">
			<div class="pn-field"><label><?= $t('Amount (EUR)', 'Monto (EUR)') ?></label><input class="pn-input" type="number" step="0.01" min="0.01" name="amount_eur" required></div>
			<div class="pn-field"><label><?= $t('Date', 'Fecha') ?></label><input class="pn-input" type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
			<div class="pn-field"><label><?= $t('Customer', 'Cliente') ?></label><input class="pn-input" name="customer"></div>
			<div class="pn-field"><label><?= $t('Category', 'Categoría') ?></label><input class="pn-input" name="category" placeholder="Booking Deposit" required></div>
			<div class="pn-field"><label><?= $t('Handled by', 'Atendido por') ?></label><input class="pn-input" name="handled_by"></div>
			<div class="pn-field"><label><?= $t('Notes', 'Notas') ?></label><input class="pn-input" name="notes"></div>
			<button class="pn-btn" type="submit"><?= $t('Log Income', 'Registrar Ingreso') ?></button>
		</form>
	</div>
	<div class="pn-card">
		<h3><?= $t('Log Expense', 'Registrar Gasto') ?></h3>
		<form class="pn-cash-form" data-action="log_expense">
			<div class="pn-field"><label><?= $t('Amount (EUR)', 'Monto (EUR)') ?></label><input class="pn-input" type="number" step="0.01" min="0.01" name="amount_eur" required></div>
			<div class="pn-field"><label><?= $t('Date', 'Fecha') ?></label><input class="pn-input" type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
			<div class="pn-field"><label><?= $t('Customer', 'Cliente') ?></label><input class="pn-input" name="customer"></div>
			<div class="pn-field"><label><?= $t('Category', 'Categoría') ?></label><input class="pn-input" name="category" placeholder="Supplies" required></div>
			<div class="pn-field"><label><?= $t('Handled by', 'Atendido por') ?></label><input class="pn-input" name="handled_by"></div>
			<div class="pn-field"><label><?= $t('Notes', 'Notas') ?></label><input class="pn-input" name="notes"></div>
			<button class="pn-btn pn-btn-danger" type="submit"><?= $t('Log Expense', 'Registrar Gasto') ?></button>
		</form>
	</div>
</div>

<div class="pn-card">
	<h3><?= $t('Unpaid Guest Surcharges Available', 'Cargos Extra Pendientes de Huéspedes') ?></h3>
	<?php if (empty($open_surcharges)): ?>
		<div class="pn-empty"><?= $t('No open surcharges to collect.', 'No hay cargos extra pendientes.') ?></div>
	<?php else: ?>
		<table class="pn-table">
			<thead><tr><th><?= $t('Date', 'Fecha') ?></th><th><?= $t('Room', 'Habitación') ?></th><th><?= $t('Guest', 'Huésped') ?></th><th><?= $t('Amount', 'Monto') ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ($open_surcharges as $s): ?>
				<tr>
					<td><?= htmlspecialchars($s['date']) ?></td>
					<td><?= htmlspecialchars($s['room']) ?></td>
					<td><?= htmlspecialchars($s['guest_name']) ?></td>
					<td><?= panther_eur($s['amount_eur']) ?></td>
					<td><button class="pn-btn pn-btn-sm pn-collect" data-id="<?= (int) $s['id'] ?>"><?= $t('Collect', 'Cobrar') ?> <?= panther_eur($s['amount_eur']) ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="pn-card">
	<h3><?= $t('Master Ledger', 'Libro Mayor') ?></h3>
	<form method="get" style="margin-bottom:10px;display:flex;gap:8px;">
		<input class="pn-input pn-search" name="q" placeholder="<?= $t('Search…', 'Buscar…') ?>" value="<?= htmlspecialchars($search ?: '') ?>">
		<select class="pn-select" name="type">
			<option value=""><?= $t('All types', 'Todos los tipos') ?></option>
			<option value="in" <?= $type === 'in' ? 'selected' : '' ?>><?= $t('Income', 'Ingreso') ?></option>
			<option value="out" <?= $type === 'out' ? 'selected' : '' ?>><?= $t('Expense', 'Gasto') ?></option>
		</select>
		<button class="pn-btn pn-btn-secondary pn-btn-sm" type="submit"><?= $t('Search', 'Buscar') ?></button>
	</form>
	<?php if (empty($transactions)): ?>
		<div class="pn-empty"><?= $t('No transactions yet.', 'Aún no hay transacciones.') ?></div>
	<?php else: ?>
	<table class="pn-table">
		<thead><tr>
			<th><?= $t('Date', 'Fecha') ?></th><th><?= $t('Type', 'Tipo') ?></th><th><?= $t('Category', 'Categoría') ?></th>
			<th><?= $t('Customer', 'Cliente') ?></th><th><?= $t('Handled by', 'Atendido por') ?></th><th><?= $t('Amount', 'Monto') ?></th><th><?= $t('Notes', 'Notas') ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ($transactions as $tx): ?>
			<tr>
				<td><?= htmlspecialchars($tx['date']) ?></td>
				<td><span class="pn-status-pill <?= $tx['type'] === 'in' ? 'pn-status-checkin' : 'pn-status-checkout' ?>"><?= $tx['type'] === 'in' ? $t('In', 'Entrada') : $t('Out', 'Salida') ?></span></td>
				<td><?= htmlspecialchars($tx['category']) ?></td>
				<td><?= htmlspecialchars($tx['customer']) ?></td>
				<td><?= htmlspecialchars($tx['handled_by']) ?></td>
				<td><?= panther_eur($tx['amount_eur']) ?></td>
				<td><?= htmlspecialchars($tx['notes']) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<script>
var base = document.getElementById('pn-base-url').value;

document.querySelectorAll('.pn-cash-form').forEach(function (form) {
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		jQuery.post(base + 'panther_cash_register/' + form.getAttribute('data-action'), jQuery(form).serialize(), function (resp) {
			if (resp.success) location.reload(); else alert(resp.message);
		}, 'json');
	});
});

document.querySelectorAll('.pn-collect').forEach(function (btn) {
	btn.addEventListener('click', function () {
		jQuery.post(base + 'panther_cash_register/collect/' + btn.getAttribute('data-id'), {}, function (resp) {
			if (resp.success) location.reload(); else alert(resp.message);
		}, 'json');
	});
});
</script>
