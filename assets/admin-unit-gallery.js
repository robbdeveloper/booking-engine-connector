(function ($) {
	'use strict';

	function openAttachmentModal(attachmentId) {
		if (!attachmentId || typeof wp === 'undefined' || !wp.media) {
			return;
		}
		var l10n = typeof becUnitGallery !== 'undefined' ? becUnitGallery.i18n : {};
		var frame = wp.media({
			title: l10n.frameTitle || '',
			library: { type: 'image' },
			multiple: false,
			button: { text: l10n.frameButton || '' },
		});
		frame.on('open', function () {
			var selection = frame.state().get('selection');
			selection.reset();
			var attachment = wp.media.attachment(attachmentId);
			attachment.fetch().done(function () {
				selection.add(attachment);
			});
		});
		frame.open();
	}

	$(function () {
		var $root = $('[data-bec-unit-gallery-root]');
		if (!$root.length) {
			return;
		}
		$root.on('click', '.bec-unit-gallery-open', function (e) {
			e.preventDefault();
			var id = parseInt(
				$(this).closest('.bec-unit-gallery-item').data('attachment-id'),
				10
			);
			openAttachmentModal(id);
		});
	});
})(jQuery);
