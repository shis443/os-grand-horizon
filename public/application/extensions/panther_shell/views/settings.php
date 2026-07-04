<?php $t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; }; ?>
<div class="pn-card">
	<h3><?= $t('Branding', 'Marca') ?></h3>
	<p><strong>Sea Panther Reservas</strong> — <?= $t('Panther Admin console, built on miniCal.', 'Consola Panther Admin, construida sobre miniCal.') ?></p>
	<p><?= $t('Theme and language can be changed from the top bar on any page.', 'El tema y el idioma se pueden cambiar desde la barra superior en cualquier página.') ?></p>
</div>

<div class="pn-card">
	<h3><?= $t('Reset Data', 'Restablecer Datos') ?></h3>
	<p><?= $t('Clears surcharges, cash register entries, cleaning requests, and the audit log for this property, then reseeds a demo July sheet. Core booking data is not affected.', 'Borra los cargos extra, la caja registradora, las solicitudes de limpieza y el registro de auditoría de esta propiedad, y vuelve a cargar una hoja de demostración de julio. Los datos de reservas del núcleo no se ven afectados.') ?></p>
	<button id="pn-reset-data" class="pn-btn pn-btn-danger"><?= $t('Reset Demo Data', 'Restablecer Datos de Demo') ?></button>
</div>

<script>
document.getElementById('pn-reset-data').addEventListener('click', function () {
	if (!confirm('<?= $t('This will clear surcharges, cash register, cleaning requests, and the audit log, then reseed demo data. Continue?', 'Esto borrará los cargos extra, la caja, las solicitudes de limpieza y el registro de auditoría, y volverá a cargar datos de demostración. ¿Continuar?') ?>')) return;
	jQuery.post(document.getElementById('pn-base-url').value + 'panther_shell/reset_data', {}, function (resp) {
		if (resp.success) { alert('Done.'); location.reload(); }
	}, 'json');
});
</script>
