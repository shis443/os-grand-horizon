<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Audit Trail Log', 'Registro de Auditoría') ?></h2>
<p style="color:var(--pn-text-muted);"><?= $t('Append-only. Nothing here can be edited; "Clear History" archives every entry (nothing is deleted) and is itself logged.', 'Solo agregar. Nada aquí se puede editar; "Borrar Historial" archiva cada entrada (no se elimina nada) y esta acción también queda registrada.') ?></p>

<?php if ($is_admin): ?>
<div class="pn-card">
	<button id="pn-clear-history" class="pn-btn pn-btn-danger"><?= $t('Clear History', 'Borrar Historial') ?></button>
</div>
<?php endif; ?>

<div class="pn-card">
	<?php if (empty($entries)): ?>
		<div class="pn-empty"><?= $t('No audit entries yet.', 'Aún no hay entradas de auditoría.') ?></div>
	<?php else: ?>
	<table class="pn-table">
		<thead><tr>
			<th><?= $t('Time (UTC)', 'Hora (UTC)') ?></th><th><?= $t('Type', 'Tipo') ?></th><th><?= $t('Room', 'Habitación') ?></th><th><?= $t('User', 'Usuario') ?></th><th><?= $t('Message', 'Mensaje') ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ($entries as $e): ?>
			<tr>
				<td><?= htmlspecialchars($e['utc_timestamp']) ?></td>
				<td><?= htmlspecialchars($e['type']) ?></td>
				<td><?= htmlspecialchars($e['room']) ?></td>
				<td><?= htmlspecialchars($e['user_label']) ?></td>
				<td><?= htmlspecialchars($e['message']) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<?php if ($is_admin): ?>
<script>
document.getElementById('pn-clear-history').addEventListener('click', function () {
	if (!confirm('<?= $t('Archive and clear the visible audit log? This action is itself logged.', '¿Archivar y borrar el registro de auditoría visible? Esta acción también queda registrada.') ?>')) return;
	jQuery.post(document.getElementById('pn-base-url').value + 'panther_audit_log/clear', {}, function (resp) {
		if (resp.success) location.reload();
	}, 'json');
});
</script>
<?php endif; ?>
