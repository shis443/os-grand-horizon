<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<h2><?= $t('Cleaning Schedule', 'Horario de Limpieza') ?></h2>

<?php if (empty($tasks)): ?>
	<div class="pn-card pn-empty"><?= $t('No rooms need cleaning right now.', 'Ninguna habitación necesita limpieza en este momento.') ?></div>
<?php else: ?>
	<div class="pn-cards-row">
		<?php foreach ($tasks as $task): ?>
			<div class="pn-card" style="min-width:240px;">
				<h3><?= htmlspecialchars($task['room_name']) ?></h3>
				<p>
					<span class="pn-status-pill pn-status-cleaning"><?= htmlspecialchars($task['room_status'] === 'Dirty' ? $t('Dirty', 'Sucia') : $t('Cleaning Requested', 'Limpieza Solicitada')) ?></span>
				</p>
				<p style="color:var(--pn-text-muted);font-size:12px;">
					<?php if (!empty($task['customer_name'])): ?>
						<?= $t('Guest', 'Huésped') ?>: <?= htmlspecialchars($task['customer_name']) ?><br>
					<?php endif; ?>
					<?php if (!empty($task['request_date'])): ?>
						<?= $t('Requested for', 'Solicitado para') ?>: <?= htmlspecialchars($task['request_date']) ?>
					<?php endif; ?>
				</p>
				<button class="pn-btn pn-mark-clean" data-room-id="<?= (int) $task['room_id'] ?>"><?= $t('Mark Clean', 'Marcar Limpia') ?></button>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<script>
document.querySelectorAll('.pn-mark-clean').forEach(function (btn) {
	btn.addEventListener('click', function () {
		var roomId = btn.getAttribute('data-room-id');
		jQuery.post(document.getElementById('pn-base-url').value + 'panther_housekeeping/mark_clean/' + roomId, {}, function (resp) {
			if (resp.success) location.reload();
		}, 'json');
	});
});
</script>
