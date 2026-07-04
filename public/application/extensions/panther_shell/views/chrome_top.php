<?php
/**
 * Shared header/sidebar chrome for every panther_* extension page.
 * Expected variables from the calling controller:
 *   $panther_active        current nav key (grid|housekeeping|room_status|surcharges|cash_register|audit_log|shell)
 *   $panther_theme         'dark' | 'light'
 *   $panther_lang          'english' | 'spanish'
 *   $panther_sim_date      Y-m-d simulator date (= company.selling_date)
 *   $panther_active_month  1-12
 *   $panther_active_year   e.g. 2026
 *   $panther_company_name
 *   $panther_badges        array: housekeeping_count, surcharges_total_label
 */
$panther_active       = isset($panther_active) ? $panther_active : '';
$panther_theme        = isset($panther_theme) ? $panther_theme : 'dark';
$panther_lang         = isset($panther_lang) ? $panther_lang : 'english';
$panther_sim_date     = isset($panther_sim_date) ? $panther_sim_date : date('Y-m-d');
$panther_active_month = isset($panther_active_month) ? (int) $panther_active_month : (int) date('n');
$panther_active_year  = isset($panther_active_year) ? (int) $panther_active_year : (int) date('Y');
$panther_company_name = isset($panther_company_name) ? $panther_company_name : 'Sea Panther Reservas';
$panther_badges       = isset($panther_badges) ? $panther_badges : array();
$base                 = base_url();

$t = function ($en, $es) use ($panther_lang) { return $panther_lang === 'spanish' ? $es : $en; };

$nav_items = array(
	array('key' => 'grid',          'label' => $t('Reservation Grid', 'Calendario de Reservas'), 'url' => 'panther_grid/month/'.$panther_active_year.'/'.$panther_active_month),
	array('key' => 'housekeeping',  'label' => $t('Housekeeping', 'Limpieza'),                    'url' => 'panther_housekeeping', 'badge' => isset($panther_badges['housekeeping_count']) ? $panther_badges['housekeeping_count'] : null),
	array('key' => 'room_status',   'label' => $t('Room Status', 'Estado de Habitaciones'),       'url' => 'panther_room_status'),
	array('key' => 'surcharges',    'label' => $t('Surcharges', 'Cargos Extra'),                  'url' => 'panther_surcharges', 'badge_text' => isset($panther_badges['surcharges_total_label']) ? $panther_badges['surcharges_total_label'] : null),
	array('key' => 'cash_register', 'label' => $t('Cash Register', 'Caja Registradora'),          'url' => 'panther_cash_register'),
	array('key' => 'audit_log',     'label' => $t('Audit Log', 'Registro de Auditoría'),          'url' => 'panther_audit_log'),
	array('key' => 'channels',      'label' => $t('Channels', 'Canales'),                         'url' => 'panther_channel', 'badge' => isset($panther_badges['channel_failed_count']) && $panther_badges['channel_failed_count'] > 0 ? $panther_badges['channel_failed_count'] : null),
);
?><!DOCTYPE html>
<html lang="<?= $panther_lang === 'spanish' ? 'es' : 'en' ?>" data-panther-theme="<?= htmlspecialchars($panther_theme) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Panther Admin — <?= htmlspecialchars($panther_company_name) ?></title>
	<link rel="icon" type="image/png" href="<?= $base ?>images/osgrandhorizon-logo.png">
	<link rel="stylesheet" href="<?= $base ?>application/extensions/panther_shell/assets/panther.css">
</head>
<body class="panther-body">
<input type="hidden" id="pn-base-url" value="<?= $base ?>">
<div class="pn-shell">
	<aside class="pn-sidebar">
		<div class="pn-brand">
			<img src="<?= $base ?>images/osgrandhorizon-logo.png" alt="OS Grand Horizon" class="pn-brand-logo">
			<strong>Panther Admin</strong>
			<span><?= htmlspecialchars($panther_company_name) ?></span>
		</div>
		<nav class="pn-nav">
			<?php foreach ($nav_items as $item): ?>
				<a href="<?= $base . $item['url'] ?>" class="<?= $panther_active === $item['key'] ? 'active' : '' ?>">
					<span><?= $item['label'] ?></span>
					<?php if (!empty($item['badge'])): ?>
						<span class="pn-badge"><?= (int) $item['badge'] ?></span>
					<?php elseif (!empty($item['badge_text'])): ?>
						<span class="pn-badge"><?= htmlspecialchars($item['badge_text']) ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
			<a href="<?= $base ?>panther_shell" class="<?= $panther_active === 'shell' ? 'active' : '' ?>">
				<span><?= $t('Settings', 'Configuración') ?></span>
			</a>
			<div class="pn-logout">
				<a href="<?= $base ?>auth/logout"><?= $t('Logout', 'Cerrar sesión') ?></a>
			</div>
		</nav>
	</aside>

	<div class="pn-main">
		<div class="pn-topbar">
			<div class="pn-topbar-group">
				<form id="pn-sim-date-form">
					<label><?= $t('Simulator Date', 'Fecha Simulada') ?></label>
					<input type="date" id="pn-sim-date" class="pn-input" value="<?= htmlspecialchars($panther_sim_date) ?>">
					<button type="submit" class="pn-btn pn-btn-sm"><?= $t('Set', 'Fijar') ?></button>
				</form>
			</div>
			<div class="pn-topbar-group">
				<input type="hidden" id="pn-active-month-hidden" value="<?= $panther_active_month ?>">
				<input type="hidden" id="pn-active-year-hidden" value="<?= $panther_active_year ?>">
				<button id="pn-prev-month" class="pn-btn pn-btn-secondary pn-btn-sm">&larr;</button>
				<form id="pn-month-form" style="display:flex;gap:6px;align-items:center;">
					<select id="pn-active-month" class="pn-select">
						<?php for ($m = 1; $m <= 12; $m++): ?>
							<option value="<?= $m ?>" <?= $m === $panther_active_month ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$m,1)) ?></option>
						<?php endfor; ?>
					</select>
					<select id="pn-active-year" class="pn-select">
						<?php for ($y = $panther_active_year - 2; $y <= $panther_active_year + 3; $y++): ?>
							<option value="<?= $y ?>" <?= $y === $panther_active_year ? 'selected' : '' ?>><?= $y ?></option>
						<?php endfor; ?>
					</select>
					<button type="submit" class="pn-btn pn-btn-secondary pn-btn-sm"><?= $t('Go', 'Ir') ?></button>
				</form>
				<button id="pn-next-month" class="pn-btn pn-btn-secondary pn-btn-sm">&rarr;</button>
				<button id="pn-sync-to-month" class="pn-btn pn-btn-sm"><?= $t('Sync to Month', 'Sincronizar Mes') ?></button>
			</div>
			<div class="pn-topbar-group">
				<select id="pn-lang-toggle" class="pn-select">
					<option value="english" <?= $panther_lang === 'english' ? 'selected' : '' ?>>English</option>
					<option value="spanish" <?= $panther_lang === 'spanish' ? 'selected' : '' ?>>Español</option>
				</select>
				<button id="pn-theme-toggle" class="pn-btn pn-btn-secondary pn-btn-sm"><?= $panther_theme === 'dark' ? '🌙 Dark' : '☀️ Light' ?></button>
			</div>
		</div>
		<div class="pn-content">
