/* Sea Panther Reservas — shared header control bindings */
(function ($) {
	'use strict';

	function post(url, data, done) {
		$.ajax({ url: url, type: 'POST', data: data, dataType: 'json' })
			.done(function (resp) { if (done) done(resp); })
			.fail(function (xhr) { alert('Request failed: ' + xhr.status); });
	}

	$(function () {
		var base = $('#pn-base-url').val();

		$('#pn-theme-toggle').on('click', function () {
			var current = document.documentElement.getAttribute('data-panther-theme');
			var next = current === 'dark' ? 'light' : 'dark';
			post(base + 'panther_shell/set_theme', { theme: next }, function () {
				document.documentElement.setAttribute('data-panther-theme', next);
				$('#pn-theme-toggle').text(next === 'dark' ? '🌙 Dark' : '☀️ Light');
			});
		});

		$('#pn-lang-toggle').on('change', function () {
			post(base + 'panther_shell/set_lang', { lang: $(this).val() }, function () {
				location.reload();
			});
		});

		$('#pn-sim-date-form').on('submit', function (e) {
			e.preventDefault();
			var date = $('#pn-sim-date').val();
			post(base + 'panther_shell/set_simulator_date', { date: date }, function (resp) {
				if (resp.success) location.reload();
				else alert(resp.message || 'Could not set simulator date.');
			});
		});

		$('#pn-month-form').on('submit', function (e) {
			e.preventDefault();
			var month = $('#pn-active-month').val();
			var year = $('#pn-active-year').val();
			location.href = window.location.pathname.replace(/\/\d{4}\/\d{1,2}$/, '') + '/' + year + '/' + month;
		});

		$('#pn-sync-to-month').on('click', function () {
			post(base + 'panther_shell/sync_to_month', {}, function (resp) {
				if (resp.success) location.href = base + resp.redirect;
			});
		});

		$('#pn-prev-month, #pn-next-month').on('click', function () {
			var delta = $(this).is('#pn-prev-month') ? -1 : 1;
			var month = parseInt($('#pn-active-month-hidden').val(), 10);
			var year = parseInt($('#pn-active-year-hidden').val(), 10);
			month += delta;
			if (month < 1) { month = 12; year -= 1; }
			if (month > 12) { month = 1; year += 1; }
			var path = window.location.pathname.replace(/\/\d{4}\/\d{1,2}$/, '');
			location.href = path + '/' + year + '/' + month;
		});
	});
})(jQuery);
