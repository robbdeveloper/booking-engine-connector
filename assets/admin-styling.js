/**
 * Initialize WP code editor (CodeMirror) on Styling page textareas.
 */
(function (wp) {
	'use strict';

	var ids = [
		'bec_styling_theme_variables',
		'bec_styling_search_extra_css',
		'bec_styling_summary_extra_css',
		'bec_styling_filters_extra_css',
	];

	function boot() {
		var cfg =
			typeof window.becStylingCodeEditor !== 'undefined'
				? window.becStylingCodeEditor
				: { disabled: true };

		if (cfg.disabled || typeof wp === 'undefined' || !wp.codeEditor) {
			return;
		}

		ids.forEach(function (id) {
			var el = document.getElementById(id);
			if (el) {
				wp.codeEditor.initialize(el, cfg);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window.wp);
