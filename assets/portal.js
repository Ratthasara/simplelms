document.addEventListener('DOMContentLoaded', function () {
	initPortalChrome();
	initSummaryActionForms();
	initManagementActionGroups();
	initRecordEditors();
	initStudentSubjectPickers();
	initPhotoModal();
	initHeaderImageModal();
	initTranscriptPrint();
	initIdCardSave();
	initAttendanceBoard();
	initAttendanceModal();
	initAttendanceWeekTools();
	initAttendanceReviewCollapsibles();
	initSubjectEmbedUrlRepeaters();
	initSubjectFileRepeaters();
	initSubjectMedia();
	initDiscussionReplyMenus();
	initDiscussionChildReplyForms();
	initResultsEditor();
	initAssignmentAjaxGrading();
	initBroadcastModals();
});

function initPortalChrome() {
	document.querySelectorAll('.slms-portal-page-free').forEach(function (portal) {
		var hostCard = portal.closest('.ugp-card');
		var hostText = portal.closest('.ugp-card-text');
		var hostShell = portal.closest('.ugp-section-inner');

		if (hostShell) {
			hostShell.classList.add('slms-portal-host-shell');
		}

		if (hostCard) {
			hostCard.classList.add('slms-portal-host-card');

			var hostTitle = hostCard.querySelector('.ugp-card-title');

			if (hostTitle) {
				hostTitle.classList.add('slms-portal-host-title');
				hostTitle.setAttribute('aria-hidden', 'true');
			}
		}

		if (hostText) {
			hostText.classList.add('slms-portal-host-text');
		}
	});
}

function fitCropperFrame(cropperInstance) {
	if (!cropperInstance) {
		return;
	}

	var containerData = cropperInstance.getContainerData();

	if (!containerData || !containerData.width || !containerData.height) {
		return;
	}

	cropperInstance.setCropBoxData({
		left: 0,
		top: 0,
		width: containerData.width,
		height: containerData.height
	});
}

function fitCropperImageToCover(cropperInstance) {
	if (!cropperInstance) {
		return;
	}

	var containerData = cropperInstance.getContainerData();
	var imageData = cropperInstance.getImageData();

	if (!containerData || !imageData || !containerData.width || !containerData.height || !imageData.naturalWidth || !imageData.naturalHeight) {
		return;
	}

	var coverRatio = Math.max(
		containerData.width / imageData.naturalWidth,
		containerData.height / imageData.naturalHeight
	);

	cropperInstance.zoomTo(coverRatio);

	var fittedImageData = cropperInstance.getImageData();

	if (!fittedImageData || !fittedImageData.width || !fittedImageData.height) {
		return;
	}

	cropperInstance.setCanvasData({
		left: (containerData.width - fittedImageData.width) / 2,
		top: (containerData.height - fittedImageData.height) / 2
	});
	fitCropperFrame(cropperInstance);
}

function promoteModalToTopLayer(modal) {
	if (!modal || !document.body || document.body === modal.parentNode) {
		return;
	}

	document.body.appendChild(modal);
}

function initSummaryActionForms(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-summary-action]').forEach(function (node) {
		if ('1' === node.dataset.slmsSummaryActionBound) {
			return;
		}

		node.dataset.slmsSummaryActionBound = '1';

		['click', 'mousedown', 'pointerdown', 'keydown'].forEach(function (eventName) {
			node.addEventListener(eventName, function (event) {
				event.stopPropagation();
			});
		});
	});

	scope.querySelectorAll('[data-slms-summary-action-button]').forEach(function (button) {
		if ('1' === button.dataset.slmsSummaryActionBound) {
			return;
		}

		button.dataset.slmsSummaryActionBound = '1';

		['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart', 'touchend'].forEach(function (eventName) {
			button.addEventListener(eventName, function (event) {
				event.preventDefault();
				event.stopPropagation();
				if (event.stopImmediatePropagation) {
					event.stopImmediatePropagation();
				}
			});
		});

		button.addEventListener('keydown', function (event) {
			if ('Enter' === event.key || ' ' === event.key || 'Spacebar' === event.key) {
				event.preventDefault();
				event.stopPropagation();
			}
		});

		button.addEventListener('keyup', function (event) {
			if ('Enter' === event.key || ' ' === event.key || 'Spacebar' === event.key) {
				event.preventDefault();
				event.stopPropagation();
			}
		});

		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (event.stopImmediatePropagation) {
				event.stopImmediatePropagation();
			}

			var formId = button.getAttribute('form');
			var form = formId ? document.getElementById(formId) : button.closest('form');
			var details = button.closest('details');
			var keepOpenField = form ? form.querySelector('[data-slms-keep-open]') : null;

			if (!form) {
				return;
			}

			if (keepOpenField) {
				keepOpenField.value = details && details.hasAttribute('open') ? '1' : '0';
			}

			button.disabled = true;
			form.submit();
		});
	});
}

function initManagementActionGroups(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-action-group]').forEach(function (group) {
		if ('1' === group.dataset.slmsActionGroupBound) {
			return;
		}

		group.dataset.slmsActionGroupBound = '1';

		var buttons = Array.prototype.slice.call(group.querySelectorAll('[data-slms-action-trigger]'));
		var panels = Array.prototype.slice.call(group.querySelectorAll('[data-slms-action-panel]'));
		var sharedWindow = group.querySelector('[data-slms-action-window]');

		function closeAll() {
			buttons.forEach(function (button) {
				button.classList.remove('is-active');
				button.setAttribute('aria-expanded', 'false');
			});

			panels.forEach(function (panel) {
				panel.hidden = true;
			});

			if (sharedWindow) {
				sharedWindow.hidden = true;
			}
		}

		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var targetKey = button.getAttribute('data-slms-action-trigger');
				var targetPanel = targetKey ? group.querySelector('[data-slms-action-panel="' + targetKey + '"]') : null;
				var isActive = button.classList.contains('is-active');

				if (!targetPanel) {
					return;
				}

				if (isActive) {
					closeAll();
					return;
				}

				buttons.forEach(function (otherButton) {
					var isTarget = otherButton === button;
					otherButton.classList.toggle('is-active', isTarget);
					otherButton.setAttribute('aria-expanded', isTarget ? 'true' : 'false');
				});

				panels.forEach(function (panel) {
					panel.hidden = panel !== targetPanel;
				});

				if (sharedWindow) {
					sharedWindow.hidden = false;
				}
			});
		});
	});
}


function initDiscussionReplyMenus(root) {
	var scope = root && root.querySelectorAll ? root : document;
	var menus = Array.prototype.slice.call(scope.querySelectorAll('[data-slms-reply-menu]'));

	menus.forEach(function (menu) {
		if ('1' === menu.dataset.slmsReplyMenuBound) {
			return;
		}

		menu.dataset.slmsReplyMenuBound = '1';

		menu.addEventListener('toggle', function () {
			if (!menu.open) {
				return;
			}

			menus.forEach(function (otherMenu) {
				if (otherMenu !== menu) {
					otherMenu.removeAttribute('open');
				}
			});
		});
	});

	document.addEventListener('click', function (event) {
		menus.forEach(function (menu) {
			if (menu.open && !menu.contains(event.target)) {
				menu.removeAttribute('open');
			}
		});
	});
}


function initDiscussionChildReplyForms(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-child-reply-toggle]').forEach(function (button) {
		if ('1' === button.dataset.slmsChildReplyBound) {
			return;
		}

		button.dataset.slmsChildReplyBound = '1';

		button.addEventListener('click', function () {
			var wrap = button.closest('[data-slms-child-reply-wrap]');
			var panel = wrap ? wrap.querySelector('[data-slms-child-reply-panel]') : null;
			var willOpen = panel ? panel.hidden : false;

			if (!panel) {
				return;
			}

			panel.hidden = !willOpen;
			button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			button.classList.toggle('is-active', willOpen);

			if (willOpen) {
				var firstField = panel.querySelector('textarea:not([disabled]), input:not([type="hidden"]), select');
				if (firstField) {
					firstField.focus();
				}
			}
		});
	});
}


function initRecordEditors() {
	document.querySelectorAll('[data-slms-record-edit-form]').forEach(function (form) {
		form.hidden = true;
	});

	document.querySelectorAll('[data-slms-record-edit-toggle]').forEach(function (button) {
		if ('1' === button.dataset.slmsRecordEditToggleBound) {
			return;
		}

		button.dataset.slmsRecordEditToggleBound = '1';

		button.addEventListener('click', function (event) {
			event.preventDefault();

			var body = button.closest('[data-slms-record-body]');
			var view = body ? body.querySelector('[data-slms-record-view]') : null;
			var form = body ? body.querySelector('[data-slms-record-edit-form]') : null;
			var firstField = form ? form.querySelector('textarea:not([disabled]), input:not([type=\"hidden\"]):not([disabled]), select:not([disabled])') : null;

			if (!body || !form) {
				return;
			}

			body.classList.add('is-editing');

			if (view) {
				view.hidden = true;
			}

			form.hidden = false;

			var replyMenu = button.closest('[data-slms-reply-menu]');

			if (replyMenu) {
				replyMenu.removeAttribute('open');
			}

			if (firstField) {
				firstField.focus();
			}
		});
	});

	document.querySelectorAll('[data-slms-record-edit-cancel]').forEach(function (button) {
		if ('1' === button.dataset.slmsRecordEditCancelBound) {
			return;
		}

		button.dataset.slmsRecordEditCancelBound = '1';

		button.addEventListener('click', function (event) {
			event.preventDefault();

			var body = button.closest('[data-slms-record-body]');
			var view = body ? body.querySelector('[data-slms-record-view]') : null;
			var form = body ? body.querySelector('[data-slms-record-edit-form]') : null;

			if (!body || !form) {
				return;
			}

			body.classList.remove('is-editing');
			form.hidden = true;

			if ('function' === typeof form.reset) {
				form.reset();
			}


			if (view) {
				view.hidden = false;
			}
		});
	});
}

function initStudentSubjectPickers(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-student-subject-picker]').forEach(function (picker) {
		if ('1' === picker.dataset.slmsSubjectPickerBound) {
			return;
		}

		picker.dataset.slmsSubjectPickerBound = '1';

		var form = picker.closest('form');
		var programSelect = form ? form.querySelector('[data-slms-student-program-select]') : null;
		var summary = picker.querySelector('[data-slms-subject-picker-summary]');
		var empty = picker.querySelector('[data-slms-subject-picker-empty]');
		var options = Array.prototype.slice.call(picker.querySelectorAll('[data-slms-subject-picker-option]'));
		var noProgramText = picker.getAttribute('data-slms-no-program-text') || 'Select a student program to show matching subjects.';
		var emptyText = picker.getAttribute('data-slms-empty-text') || 'No subjects are available for the selected program.';
		var noSubjectsText = picker.getAttribute('data-slms-no-subjects-text') || emptyText;

		function getSelectedProgramId() {
			return programSelect ? String(programSelect.value || '') : '';
		}

		function updateSummary(selectedCount) {
			if (!summary) {
				return;
			}

			var template = summary.getAttribute('data-slms-summary-template') || 'Choose subjects (%d selected)';
			summary.textContent = template.replace('%d', selectedCount);
		}

		function updatePicker() {
			var programId = getSelectedProgramId();
			var visibleCount = 0;
			var selectedCount = 0;

			options.forEach(function (option) {
				var optionProgramId = String(option.getAttribute('data-slms-subject-program-id') || '');
				var matchesProgram = !!programId && optionProgramId === programId;
				var availableInput = option.querySelector('[data-slms-subject-available-input]');
				var checkbox = option.querySelector('[data-slms-subject-checkbox]');
				var isLocked = checkbox && '1' === checkbox.getAttribute('data-slms-subject-locked');

				option.hidden = !matchesProgram;

				if (availableInput) {
					availableInput.disabled = !matchesProgram;
				}

				if (checkbox) {
					checkbox.disabled = !matchesProgram || isLocked;
				}

				if (matchesProgram) {
					visibleCount += 1;

					if (checkbox && checkbox.checked) {
						selectedCount += 1;
					}
				}
			});

			updateSummary(selectedCount);
			picker.classList.toggle('has-visible-options', visibleCount > 0);
			picker.classList.toggle('has-no-program', !programId);

			if (empty) {
				if (!options.length) {
					empty.textContent = noSubjectsText;
				} else if (!programId) {
					empty.textContent = noProgramText;
				} else {
					empty.textContent = emptyText;
				}

				empty.hidden = visibleCount > 0;
			}
		}

		if (programSelect) {
			programSelect.addEventListener('change', updatePicker);
		}

		options.forEach(function (option) {
			var checkbox = option.querySelector('[data-slms-subject-checkbox]');

			if (checkbox) {
				checkbox.addEventListener('change', updatePicker);
			}
		});

		if (form) {
			form.addEventListener('reset', function () {
				window.setTimeout(updatePicker, 0);
			});
		}

		updatePicker();
	});
}

function initPhotoModal() {
	var modal = document.getElementById('slms-photo-modal');

	if (!modal) {
		return;
	}

	promoteModalToTopLayer(modal);

	var form = modal.querySelector('[data-slms-photo-form]');
	var fileInput = modal.querySelector('[data-slms-photo-input]');
	var cropWrap = modal.querySelector('[data-slms-crop-wrap]');
	var cropSource = modal.querySelector('[data-slms-crop-source]');
	var staticPreview = modal.querySelector('[data-slms-static-preview]');
	var errorNode = modal.querySelector('[data-slms-photo-error]');
	var modeField = form ? form.querySelector('input[name="slms_photo_mode"]') : null;
	var deleteField = form ? form.querySelector('input[name="slms_profile_photo_delete"]') : null;
	var openButtons = document.querySelectorAll('[data-slms-open-photo-modal]');
	var closeButtons = modal.querySelectorAll('[data-slms-close-photo-modal]');
	var saveOriginal = modal.querySelector('[data-slms-save-original]');
	var saveCropped = modal.querySelector('[data-slms-save-cropped]');
	var removePhoto = modal.querySelector('[data-slms-remove-photo]');
	var cropper = null;
	var hasNewFile = false;
	var submitReady = false;
	var maxBytes = 10 * 1024 * 1024;

	function showError(message) {
		if (!errorNode) {
			window.alert(message);
			return;
		}

		errorNode.textContent = message;
		errorNode.hidden = !message;
	}

	function clearError() {
		if (!errorNode) {
			return;
		}

		errorNode.textContent = '';
		errorNode.hidden = true;
	}

	function destroyCropper() {
		if (cropper) {
			cropper.destroy();
			cropper = null;
		}
	}

	function resetModalState() {
		destroyCropper();
		clearError();
		hasNewFile = false;
		submitReady = false;

		if (fileInput) {
			fileInput.value = '';
		}

		if (modeField) {
			modeField.value = 'original';
		}

		if (deleteField) {
			deleteField.value = '0';
		}

		if (cropSource) {
			cropSource.src = '';
		}

		if (cropWrap) {
			cropWrap.hidden = true;
		}

		if (staticPreview) {
			staticPreview.hidden = false;
		}
	}

	function openModal() {
		modal.setAttribute('aria-hidden', 'false');
		modal.classList.add('is-open');
	}

	function closeModal() {
		modal.setAttribute('aria-hidden', 'true');
		modal.classList.remove('is-open');
		resetModalState();
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			openModal();
		});
	});

	closeButtons.forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			closeModal();
		});
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && modal.classList.contains('is-open')) {
			closeModal();
		}
	});

	if (fileInput && cropSource) {
		fileInput.addEventListener('change', function (event) {
			var file = event.target.files && event.target.files[0];

			clearError();

			if (!file) {
				return;
			}

			if (!file.type || file.type.indexOf('image/') !== 0) {
				showError('Please choose a valid image file.');
				fileInput.value = '';
				return;
			}

			if (file.size > maxBytes) {
				showError('Profile photos must be 10MB or smaller.');
				fileInput.value = '';
				return;
			}

			var reader = new FileReader();
			reader.onload = function (loadEvent) {
				destroyCropper();
				hasNewFile = true;

				if (modeField) {
					modeField.value = 'original';
				}

				if (staticPreview) {
					staticPreview.hidden = true;
				}

				if (cropWrap) {
					cropWrap.hidden = false;
				}

				cropSource.onload = function () {
					if (window.Cropper) {
						cropper = new window.Cropper(cropSource, {
							aspectRatio: 1,
							viewMode: 1,
							dragMode: 'move',
							autoCropArea: 1,
							restore: false,
							guides: true,
							center: true,
							highlight: false,
							cropBoxMovable: true,
							cropBoxResizable: true,
							toggleDragModeOnDblclick: false,
							ready: function () {
								setTimeout(function () {
									fitCropperFrame(cropper);
								}, 0);
							}
						});
					}
				};

				cropSource.src = loadEvent.target.result;
			};

			reader.readAsDataURL(file);
		});
	}

	if (saveOriginal && modeField && deleteField) {
		saveOriginal.addEventListener('click', function () {
			modeField.value = 'original';
			deleteField.value = '0';
		});
	}

	if (saveCropped && modeField && deleteField) {
		saveCropped.addEventListener('click', function () {
			modeField.value = 'crop';
			deleteField.value = '0';
		});
	}

	if (removePhoto && deleteField && modeField && form) {
		removePhoto.addEventListener('click', function (event) {
			event.preventDefault();
			modeField.value = 'remove';
			deleteField.value = '1';
			submitReady = true;
			form.submit();
		});
	}

	if (!form) {
		return;
	}

	form.addEventListener('submit', function (event) {
		if (submitReady) {
			submitReady = false;
			return;
		}

		clearError();

		if (deleteField && '1' === deleteField.value) {
			return;
		}

		if (!fileInput || !fileInput.files || !fileInput.files[0]) {
			event.preventDefault();
			showError('Please choose a photo first, or use Remove Photo.');
			return;
		}

		if (!modeField || 'crop' !== modeField.value || !hasNewFile) {
			return;
		}

		if (!cropper) {
			return;
		}

		event.preventDefault();

		var canvas = cropper.getCroppedCanvas({
			width: 600,
			height: 600,
			imageSmoothingEnabled: true,
			imageSmoothingQuality: 'high'
		});

		if (!canvas) {
			showError('The selected image could not be prepared for upload.');
			return;
		}

		canvas.toBlob(function (blob) {
			if (!blob) {
				showError('The selected image could not be prepared for upload.');
				return;
			}

			var originalName = fileInput.files[0].name || 'profile-photo.jpg';
			var croppedFile = new File([blob], originalName.replace(/\.[^.]+$/, '') + '.jpg', {
				type: 'image/jpeg'
			});
			var dataTransfer = new DataTransfer();

			dataTransfer.items.add(croppedFile);
			fileInput.files = dataTransfer.files;
			submitReady = true;
			form.submit();
		}, 'image/jpeg', 0.92);
	});
}

function initHeaderImageModal() {
	var modal = document.getElementById('slms-header-image-modal');

	if (!modal || '1' === modal.dataset.slmsHeaderImageModalBound) {
		return;
	}

	modal.dataset.slmsHeaderImageModalBound = '1';
	promoteModalToTopLayer(modal);

	var form = modal.querySelector('[data-slms-header-image-form]');
	var titleNode = modal.querySelector('[data-slms-header-image-modal-title]');
	var actionField = modal.querySelector('[data-slms-header-action-field]');
	var subjectField = modal.querySelector('[data-slms-header-subject-field]');
	var tabField = modal.querySelector('[data-slms-header-tab-field]');
	var viewField = modal.querySelector('[data-slms-header-view-field]');
	var deleteField = modal.querySelector('[data-slms-header-delete-field]');
	var modeField = modal.querySelector('[data-slms-header-mode-field]');
	var opacityField = modal.querySelector('[data-slms-header-opacity-field]');
	var opacityInput = modal.querySelector('[data-slms-header-opacity-input]');
	var opacityValue = modal.querySelector('[data-slms-header-opacity-value]');
	var fileInput = modal.querySelector('[data-slms-header-image-input]');
	var previewWrap = modal.querySelector('[data-slms-header-image-preview-wrap]');
	var previewImage = modal.querySelector('[data-slms-header-image-preview]');
	var cropWrap = modal.querySelector('[data-slms-header-crop-wrap]');
	var cropSource = modal.querySelector('[data-slms-header-crop-source]');
	var errorNode = modal.querySelector('[data-slms-header-image-error]');
	var removeButton = modal.querySelector('[data-slms-remove-header-image]');
	var saveCropped = modal.querySelector('[data-slms-save-header-cropped]');
	var openButtons = document.querySelectorAll('[data-slms-open-header-image-modal]');
	var closeButtons = modal.querySelectorAll('[data-slms-close-header-image-modal]');
	var objectUrl = '';
	var cropper = null;
	var hasNewFile = false;
	var submitReady = false;
	var maxBytes = 12 * 1024 * 1024;
	var headerAspectRatio = 18 / 5;
	var defaultOpacity = opacityInput ? parseInt(opacityInput.value, 10) || 38 : 38;

	function sanitizeOpacity(value) {
		var parsed = parseInt(value, 10);

		if (Number.isNaN(parsed)) {
			return defaultOpacity;
		}

		if (parsed < 0) {
			return 0;
		}

		if (parsed > 100) {
			return 100;
		}

		return parsed;
	}

	function setPreviewOpacity(value) {
		var opacity = sanitizeOpacity(value);
		var decimalOpacity = (opacity / 100).toFixed(2);

		if (opacityField) {
			opacityField.value = String(opacity);
		}

		if (opacityInput && String(opacityInput.value) !== String(opacity)) {
			opacityInput.value = String(opacity);
		}

		if (opacityValue) {
			opacityValue.textContent = opacity + '%';
		}

		if (previewWrap) {
			previewWrap.style.setProperty('--slms-header-preview-opacity', decimalOpacity);
		}

		if (cropWrap) {
			cropWrap.style.setProperty('--slms-header-preview-opacity', decimalOpacity);
		}
	}

	function showError(message) {
		if (!errorNode) {
			window.alert(message);
			return;
		}

		errorNode.textContent = message;
		errorNode.hidden = !message;
	}

	function clearError() {
		if (!errorNode) {
			return;
		}

		errorNode.textContent = '';
		errorNode.hidden = true;
	}

	function revokeObjectUrl() {
		if (objectUrl) {
			URL.revokeObjectURL(objectUrl);
			objectUrl = '';
		}
	}

	function destroyCropper() {
		if (cropper) {
			cropper.destroy();
			cropper = null;
		}
	}

	function setPreview(url) {
		if (!previewWrap || !previewImage) {
			return;
		}

		if (url) {
			previewImage.src = url;
			previewWrap.hidden = false;
			return;
		}

		previewImage.src = '';
		previewWrap.hidden = true;
	}

	function resetModalState() {
		revokeObjectUrl();
		destroyCropper();
		clearError();
		hasNewFile = false;
		submitReady = false;

		if (fileInput) {
			fileInput.value = '';
		}

		if (modeField) {
			modeField.value = 'crop';
		}

		if (deleteField) {
			deleteField.value = '0';
		}

		setPreviewOpacity(defaultOpacity);

		if (cropSource) {
			cropSource.src = '';
		}

		if (cropWrap) {
			cropWrap.hidden = true;
		}
	}

	function openModal(button) {
		var currentImage = button.getAttribute('data-slms-current-image') || '';
		var currentOpacity = button.getAttribute('data-slms-current-opacity') || String(defaultOpacity);

		resetModalState();

		if (titleNode) {
			titleNode.textContent = button.getAttribute('data-slms-header-title') || 'Edit Header Image';
		}

		if (actionField) {
			actionField.value = button.getAttribute('data-slms-header-action') || 'save_portal_header_image';
		}

		if (subjectField) {
			subjectField.value = button.getAttribute('data-slms-header-subject') || '0';
		}

		if (tabField) {
			tabField.value = button.getAttribute('data-slms-header-tab') || 'materials';
		}

		if (viewField) {
			viewField.value = button.getAttribute('data-slms-header-view') || 'dashboard';
		}

		if (deleteField) {
			deleteField.value = '0';
		}

		modal.setAttribute('data-slms-current-image', currentImage);
		setPreviewOpacity(currentOpacity);
		setPreview(currentImage);
		modal.setAttribute('aria-hidden', 'false');
		modal.classList.add('is-open');
	}

	function closeModal() {
		resetModalState();
		setPreview('');
		modal.setAttribute('aria-hidden', 'true');
		modal.classList.remove('is-open');
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			openModal(button);
		});
	});

	closeButtons.forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			closeModal();
		});
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && modal.classList.contains('is-open')) {
			closeModal();
		}
	});

	if (fileInput && cropSource) {
		fileInput.addEventListener('change', function (event) {
			var file = event.target.files && event.target.files[0];

			clearError();
			revokeObjectUrl();
			destroyCropper();
			hasNewFile = false;

			if (!file) {
				setPreview(modal.getAttribute('data-slms-current-image') || '');
				if (cropWrap) {
					cropWrap.hidden = true;
				}
				return;
			}

			if (!file.type || file.type.indexOf('image/') !== 0) {
				showError('Please choose a valid image file.');
				fileInput.value = '';
				if (cropWrap) {
					cropWrap.hidden = true;
				}
				setPreview(modal.getAttribute('data-slms-current-image') || '');
				return;
			}

			if (file.size > maxBytes) {
				showError('Header images must be 12MB or smaller.');
				fileInput.value = '';
				if (cropWrap) {
					cropWrap.hidden = true;
				}
				setPreview(modal.getAttribute('data-slms-current-image') || '');
				return;
			}

			if (deleteField) {
				deleteField.value = '0';
			}

			hasNewFile = true;
			objectUrl = URL.createObjectURL(file);

			if (previewWrap) {
				previewWrap.hidden = true;
			}

			if (cropWrap) {
				cropWrap.hidden = false;
			}

			cropSource.onload = function () {
				if (window.Cropper) {
					cropper = new window.Cropper(cropSource, {
						aspectRatio: headerAspectRatio,
						viewMode: 2,
						dragMode: 'move',
						autoCropArea: 1,
						restore: false,
						guides: false,
						center: false,
						highlight: true,
						background: false,
						movable: true,
						zoomable: true,
						zoomOnTouch: true,
						zoomOnWheel: true,
						cropBoxMovable: false,
						cropBoxResizable: false,
						toggleDragModeOnDblclick: false,
						ready: function () {
							fitCropperImageToCover(cropper);
						}
					});
				}
			};

			cropSource.src = objectUrl;
		});
	}

	if (opacityInput) {
		opacityInput.addEventListener('input', function (event) {
			setPreviewOpacity(event.target.value);
		});

		opacityInput.addEventListener('change', function (event) {
			setPreviewOpacity(event.target.value);
		});
	}

	if (saveCropped && modeField && deleteField) {
		saveCropped.addEventListener('click', function () {
			modeField.value = 'crop';
			deleteField.value = '0';
		});
	}

	if (removeButton && form && deleteField && modeField) {
		removeButton.addEventListener('click', function (event) {
			event.preventDefault();
			deleteField.value = '1';
			modeField.value = 'remove';
			submitReady = true;
			form.submit();
		});
	}

	if (form && fileInput && deleteField) {
		form.addEventListener('submit', function (event) {
			var hasSelectedFile = !!(fileInput.files && fileInput.files[0]);
			var hasCurrentImage = !!(modal.getAttribute('data-slms-current-image') || '');

			if (submitReady) {
				submitReady = false;
				return;
			}

			clearError();

			if ('1' === deleteField.value) {
				return;
			}

			if (!hasSelectedFile && hasCurrentImage) {
				return;
			}

			if (!hasSelectedFile) {
				event.preventDefault();
				showError('Please choose an image first, or use Remove Image.');
				return;
			}

			if (!modeField || 'crop' !== modeField.value || !hasNewFile) {
				return;
			}

			if (!cropper) {
				return;
			}

			event.preventDefault();

			var canvas = cropper.getCroppedCanvas({
				width: 1800,
				height: 500,
				imageSmoothingEnabled: true,
				imageSmoothingQuality: 'high'
			});

			if (!canvas) {
				showError('The selected image could not be prepared for upload.');
				return;
			}

			canvas.toBlob(function (blob) {
				if (!blob) {
					showError('The selected image could not be prepared for upload.');
					return;
				}

				var originalName = fileInput.files[0].name || 'header-image.jpg';
				var croppedFile = new File([blob], originalName.replace(/\.[^.]+$/, '') + '.jpg', {
					type: 'image/jpeg'
				});
				var dataTransfer = new DataTransfer();

				dataTransfer.items.add(croppedFile);
				fileInput.files = dataTransfer.files;
				submitReady = true;
				form.submit();
			}, 'image/jpeg', 0.92);
		});
	}
}

function buildTranscriptPrintStyles() {
	return [
		'@page { size: A4 portrait; margin: 14mm; }',
		'html, body { margin: 0; padding: 0; background: #ffffff; color: #111827; }',
		'body { font-family: "Aptos", "Segoe UI", Arial, sans-serif; font-weight: 400; }',
		'.slms-transcript-print-document, .slms-transcript-print-document * { font-weight: 400 !important; }',
		'.slms-transcript-print-document { padding: 18px 0 0; }',
		'.slms-transcript-print-sheet { display: grid; gap: 24px; }',
		'.slms-transcript-print-sheet-header { display: grid; justify-items: center; gap: 12px; padding: 0 0 12px; text-align: center; }',
		'.slms-transcript-print-sheet-logo { width: 82px; height: 82px; object-fit: contain; }',
		'.slms-transcript-print-sheet-brand { display: grid; gap: 6px; }',
		'.slms-transcript-print-sheet-brand h2 { margin: 0; font-size: 22pt; line-height: 1.15; letter-spacing: 0.01em; }',
		'.slms-transcript-print-sheet-brand p { margin: 0; font-size: 10pt; letter-spacing: 0.18em; text-transform: uppercase; color: #475569; }',
		'.slms-transcript-print-student-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; padding: 10px 0 8px; }',
		'.slms-transcript-print-student-grid div { display: grid; gap: 5px; }',
		'.slms-transcript-print-student-grid span { font-size: 8pt; letter-spacing: 0.14em; text-transform: uppercase; color: #64748b; }',
		'.slms-transcript-print-student-grid strong { font-size: 10.5pt; line-height: 1.35; }',
		'.slms-transcript-print-table { width: 100%; margin-top: 4px; border-collapse: collapse; table-layout: fixed; border: 0; }',
		'.slms-transcript-print-table thead { display: table-header-group; }',
		'.slms-transcript-print-table th, .slms-transcript-print-table td { padding: 11px 12px; text-align: left; vertical-align: top; background: #ffffff; color: #111827; word-break: break-word; }',
		'.slms-transcript-print-table thead th { border-top: 0.5px solid #8aaed4; border-bottom: 0.5px solid #8aaed4; font-size: 8.5pt; letter-spacing: 0.08em; text-transform: uppercase; }',
		'.slms-transcript-print-table tbody td { border-bottom: 0; font-size: 10pt; line-height: 1.4; }',
		'.slms-transcript-print-table tfoot th { border-top: 0.5px solid #8aaed4; border-bottom: 0.5px solid #8aaed4; font-size: 10pt; }',
		'.slms-transcript-print-table th:nth-child(1), .slms-transcript-print-table td:nth-child(1) { width: 18%; }',
		'.slms-transcript-print-table th:nth-child(2), .slms-transcript-print-table td:nth-child(2) { width: 50%; }',
		'.slms-transcript-print-table th:nth-child(3), .slms-transcript-print-table td:nth-child(3) { width: 14%; }',
		'.slms-transcript-print-table th:nth-child(4), .slms-transcript-print-table td:nth-child(4) { width: 18%; }',
		'@media print { .slms-transcript-print-document { padding: 0; } }'
	].join('');
}

function escapePrintHtml(value) {
	return String(value || '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

function waitForPrintWindowAssets(printWindow) {
	var doc = printWindow.document;
	var imagePromises = Array.prototype.slice.call(doc.images || []).map(function (image) {
		if (image.complete) {
			return Promise.resolve();
		}

		return new Promise(function (resolve) {
			function finish() {
				image.removeEventListener('load', finish);
				image.removeEventListener('error', finish);
				resolve();
			}

			image.addEventListener('load', finish);
			image.addEventListener('error', finish);
		});
	});
	var fontPromise = doc.fonts && doc.fonts.ready ? doc.fonts.ready.catch(function () {
		return null;
	}) : Promise.resolve();

	imagePromises.push(fontPromise);

	return Promise.all(imagePromises);
}

function getTranscriptPrintFrame() {
	var frame = document.getElementById('slms-transcript-print-frame');

	if (frame) {
		return frame;
	}

	frame = document.createElement('iframe');
	frame.id = 'slms-transcript-print-frame';
	frame.setAttribute('aria-hidden', 'true');
	frame.tabIndex = -1;
	frame.style.position = 'fixed';
	frame.style.right = '0';
	frame.style.bottom = '0';
	frame.style.width = '0';
	frame.style.height = '0';
	frame.style.border = '0';
	frame.style.opacity = '0';
	frame.style.pointerEvents = 'none';
	frame.style.zIndex = '-1';
	document.body.appendChild(frame);

	return frame;
}

function initTranscriptPrint() {
	document.querySelectorAll('[data-slms-transcript-print-trigger]').forEach(function (button) {
		if ('1' === button.dataset.slmsTranscriptPrintBound) {
			return;
		}

		button.dataset.slmsTranscriptPrintBound = '1';

		button.addEventListener('click', function (event) {
			event.preventDefault();

			var panel = button.closest('.slms-transcript-panel');
			var sheet = panel ? panel.querySelector('.slms-transcript-print-sheet') : null;
			var printFrame;
			var printContext;
			var printDocument;
			var printSheet;
			var titleNode;
			var titleText;

			if (!sheet) {
				window.alert('The transcript could not be prepared for printing right now.');
				return;
			}

			printSheet = sheet.cloneNode(true);
			printSheet.removeAttribute('aria-hidden');
			titleNode = printSheet.querySelector('.slms-transcript-print-sheet-brand h2');
			titleText = titleNode ? titleNode.textContent.trim() + ' - Digital Academic Transcript' : 'Digital Academic Transcript';
			printFrame = getTranscriptPrintFrame();
			printContext = printFrame.contentWindow;

			if (!printContext) {
				window.alert('The transcript could not be prepared for printing right now.');
				return;
			}

			printDocument = printContext.document;
			printDocument.open();
			printDocument.write(
				'<!doctype html><html><head><meta charset="utf-8"><title>' +
				escapePrintHtml(titleText) +
				'</title><style>' +
				buildTranscriptPrintStyles() +
				'</style></head><body class="slms-transcript-print-document">' +
				printSheet.outerHTML +
				'</body></html>'
			);
			printDocument.close();

			waitForPrintWindowAssets(printContext).then(function () {
				printContext.focus();
				printContext.print();
			});
		});
	});
}

function initIdCardSave() {
	document.querySelectorAll('[data-slms-save-id-card]').forEach(function (button) {
		button.addEventListener('click', function () {
			var targetId = button.getAttribute('data-slms-id-target');
			var filename = button.getAttribute('data-slms-id-filename') || 'simple-lms-id-card';
			var target = targetId ? document.getElementById(targetId) : null;
			var originalLabel = button.textContent;

			if (!target || !window.html2canvas) {
				window.alert('The ID card could not be saved right now.');
				return;
			}

			button.disabled = true;
			button.textContent = 'Saving...';

			function waitForCardAssets(node) {
				var imagePromises = Array.prototype.slice.call(node.querySelectorAll('img')).map(function (image) {
					if (image.complete) {
						return Promise.resolve();
					}

					return new Promise(function (resolve) {
						function finish() {
							image.removeEventListener('load', finish);
							image.removeEventListener('error', finish);
							resolve();
						}

						image.addEventListener('load', finish);
						image.addEventListener('error', finish);
					});
				});
				var fontPromise = document.fonts && document.fonts.ready ? document.fonts.ready.catch(function () {
					return null;
				}) : Promise.resolve();

				imagePromises.push(fontPromise);

				return Promise.all(imagePromises);
			}

			var rect = target.getBoundingClientRect();
			var renderWidth = Math.round(rect.width);
			var renderHeight = Math.round(rect.height);
			var renderScale = Math.max(2, window.devicePixelRatio || 1);
			var computedStyles = window.getComputedStyle(target);
			var exportBackground = computedStyles.backgroundColor && 'rgba(0, 0, 0, 0)' !== computedStyles.backgroundColor
				? computedStyles.backgroundColor
				: '#eef4fd';

			waitForCardAssets(target).then(function () {
				return window.html2canvas(target, {
					backgroundColor: exportBackground,
					scale: renderScale,
					useCORS: true,
					allowTaint: false,
					logging: false,
					imageTimeout: 0,
					removeContainer: true,
					width: renderWidth,
					height: renderHeight,
					windowWidth: document.documentElement.clientWidth,
					windowHeight: document.documentElement.clientHeight,
					scrollX: 0,
					scrollY: -window.scrollY,
					onclone: function (clonedDocument) {
						var clonedTarget = targetId ? clonedDocument.getElementById(targetId) : null;

						if (!clonedTarget) {
							return;
						}

						clonedTarget.style.borderRadius = '0';
						clonedTarget.style.overflow = 'hidden';
					}
				});
			}).then(function (canvas) {
				var link = document.createElement('a');
				link.href = canvas.toDataURL('image/jpeg', 0.98);
				link.download = filename + '.jpg';
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
			}).catch(function () {
				window.alert('The ID card could not be saved right now.');
			}).finally(function () {
				button.disabled = false;
				button.textContent = originalLabel;
			});
		});
	});
}

function initAttendanceBoard() {
	var validStates = ['present', 'late', 'leave', 'absent'];

	function normalizeState(state) {
		return validStates.indexOf(state) > -1 ? state : 'absent';
	}

	function applyState(card, state) {
		var nextState = normalizeState(state);
		var input = card.querySelector('input[type="hidden"]');
		var toggle = card.querySelector('[data-slms-attendance-toggle]');

		if (input) {
			input.value = nextState;
		}

		card.setAttribute('data-state', nextState);
		validStates.forEach(function (candidate) {
			card.classList.toggle('is-' + candidate, candidate === nextState);
		});

		if (toggle) {
			toggle.setAttribute('aria-pressed', ('present' === nextState || 'late' === nextState) ? 'true' : 'false');
		}

		card.querySelectorAll('[data-slms-attendance-special]').forEach(function (button) {
			var specialState = button.getAttribute('data-slms-attendance-special');
			var active = specialState === nextState;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	}

	function toggleCardPresence(card) {
		var currentState = normalizeState(card.getAttribute('data-state'));
		applyState(card, ('present' === currentState || 'late' === currentState) ? 'absent' : 'present');
	}

	document.querySelectorAll('[data-slms-attendance-card]').forEach(function (card) {
		applyState(card, card.getAttribute('data-state'));

		card.addEventListener('pointerdown', function (event) {
			card.dataset.slmsPointerStartX = String(event.clientX);
			card.dataset.slmsPointerStartY = String(event.clientY);
			delete card.dataset.slmsPointerMoved;
		}, { passive: true });

		card.addEventListener('pointermove', function (event) {
			var startX = parseFloat(card.dataset.slmsPointerStartX || '0');
			var startY = parseFloat(card.dataset.slmsPointerStartY || '0');

			if (Math.abs(event.clientX - startX) > 8 || Math.abs(event.clientY - startY) > 8) {
				card.dataset.slmsPointerMoved = '1';
			}
		}, { passive: true });

		card.addEventListener('click', function (event) {
			if ('1' === card.dataset.slmsPointerMoved) {
				delete card.dataset.slmsPointerMoved;
				return;
			}

			if (event.target.closest('[data-slms-attendance-special]') || event.target.closest('input, select, textarea, a')) {
				return;
			}

			toggleCardPresence(card);
		});
	});

	document.querySelectorAll('[data-slms-attendance-toggle]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			var card = button.closest('[data-slms-attendance-card]');

			if (!card) {
				return;
			}

			toggleCardPresence(card);
		});
	});

	document.querySelectorAll('[data-slms-attendance-special]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			var card = button.closest('[data-slms-attendance-card]');
			var specialState = normalizeState(button.getAttribute('data-slms-attendance-special'));

			if (!card || ['late', 'leave'].indexOf(specialState) === -1) {
				return;
			}

			var currentState = normalizeState(card.getAttribute('data-state'));

			if (currentState === specialState) {
				applyState(card, 'late' === specialState ? 'present' : 'absent');
				return;
			}

			applyState(card, specialState);
		});
	});

	document.querySelectorAll('[data-slms-attendance-mark]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			var state = normalizeState(button.getAttribute('data-slms-attendance-mark'));
			var form = button.closest('form');

			if (!form) {
				return;
			}

			form.querySelectorAll('[data-slms-attendance-card]').forEach(function (studentCard) {
				applyState(studentCard, state);
			});
		});
	});
}


function initAttendanceModal() {
	var openButtons = document.querySelectorAll('[data-slms-modal-open]');
	var activeModal = null;

	function closeModal(modal) {
		if (!modal) {
			return;
		}

		modal.setAttribute('aria-hidden', 'true');
		modal.classList.remove('is-open');
		activeModal = null;
	}

	function openModal(modal) {
		if (!modal) {
			return;
		}

		modal.setAttribute('aria-hidden', 'false');
		modal.classList.add('is-open');
		activeModal = modal;
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			var modalId = button.getAttribute('data-slms-modal-open');
			var modal = modalId ? document.getElementById(modalId) : null;

			openModal(modal);
		});
	});

	document.querySelectorAll('.slms-attendance-modal').forEach(function (modal) {
		modal.querySelectorAll('[data-slms-modal-close]').forEach(function (button) {
			button.addEventListener('click', function () {
				closeModal(modal);
			});
		});
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && activeModal) {
			closeModal(activeModal);
		}
	});
}


function initAttendanceWeekTools() {
	function jumpToAttendanceTarget(targetId) {
		if (!targetId) {
			return;
		}

		var target = document.getElementById(targetId);

		if (!target) {
			return;
		}

		target.scrollIntoView({ behavior: 'smooth', block: 'center' });
		target.classList.add('is-attendance-jump-highlight');

		if (target.focus) {
			target.focus({ preventScroll: true });
		}

		window.setTimeout(function () {
			target.classList.remove('is-attendance-jump-highlight');
		}, 1800);
	}

	document.querySelectorAll('[data-slms-attendance-jump]').forEach(function (button) {
		button.addEventListener('click', function () {
			jumpToAttendanceTarget(button.getAttribute('data-slms-attendance-target'));
		});
	});

	document.querySelectorAll('[data-slms-attendance-week-tools]').forEach(function (tools) {
		var select = tools.querySelector('[data-slms-attendance-session-select]');
		var go = tools.querySelector('[data-slms-attendance-select-go]');

		if (!select || !go) {
			return;
		}

		go.addEventListener('click', function () {
			jumpToAttendanceTarget(select.value);
		});

		select.addEventListener('change', function () {
			jumpToAttendanceTarget(select.value);
		});
	});
}

function initSubjectMedia(root) {
	var scope = root && root.querySelectorAll ? root : document;
	var viewer = document.getElementById('slms-media-viewer');
	var viewerImage = viewer ? viewer.querySelector('[data-slms-viewer-image]') : null;
	var viewerCaption = viewer ? viewer.querySelector('[data-slms-viewer-caption]') : null;

	function closeViewer() {
		if (!viewer) {
			return;
		}

		viewer.setAttribute('aria-hidden', 'true');
		viewer.classList.remove('is-open');

		if (viewerImage) {
			viewerImage.setAttribute('src', '');
			viewerImage.setAttribute('alt', '');
		}

		if (viewerCaption) {
			viewerCaption.textContent = '';
			viewerCaption.hidden = true;
		}
	}

	if (viewer && viewerImage) {
		scope.querySelectorAll('[data-slms-lightbox-image]').forEach(function (button) {
			if ('1' === button.dataset.slmsLightboxBound) {
				return;
			}

			button.dataset.slmsLightboxBound = '1';

			button.addEventListener('click', function () {
				var imageUrl = button.getAttribute('data-slms-lightbox-image');
				var altText = button.getAttribute('data-slms-lightbox-alt') || '';

				if (!imageUrl) {
					return;
				}

				viewerImage.setAttribute('src', imageUrl);
				viewerImage.setAttribute('alt', altText);

				if (viewerCaption) {
					viewerCaption.textContent = altText;
					viewerCaption.hidden = !altText;
				}

				viewer.setAttribute('aria-hidden', 'false');
				viewer.classList.add('is-open');
			});
		});

		viewer.querySelectorAll('[data-slms-viewer-close]').forEach(function (button) {
			if ('1' === button.dataset.slmsViewerCloseBound) {
				return;
			}

			button.dataset.slmsViewerCloseBound = '1';
			button.addEventListener('click', closeViewer);
		});

		if ('1' !== viewer.dataset.slmsViewerEscapeBound) {
			viewer.dataset.slmsViewerEscapeBound = '1';

			document.addEventListener('keydown', function (event) {
				if ('Escape' === event.key && viewer.classList.contains('is-open')) {
					closeViewer();
				}
			});
		}
	}

	scope.querySelectorAll('[data-slms-audio-rate]').forEach(function (select) {
		if ('1' === select.dataset.slmsAudioRateBound) {
			return;
		}

		select.dataset.slmsAudioRateBound = '1';

		select.addEventListener('change', function () {
			var card = select.closest('[data-slms-audio-card]');
			var player = card ? card.querySelector('audio') : null;
			var nextRate = parseFloat(select.value || '1');

			if (!player || !nextRate) {
				return;
			}

			player.playbackRate = nextRate;
		});
	});
}

function initResultsEditor() {
	document.querySelectorAll('[data-slms-results-row]').forEach(function (row) {
		var editButton = row.querySelector('[data-slms-results-edit]');
		var attendanceInput = row.querySelector('[data-slms-results-attendance]');
		var assignmentInput = row.querySelector('[data-slms-results-assignment-score]');
		var finalExamInput = row.querySelector('[data-slms-results-final-exam]');
		var totalCell = row.querySelector('[data-slms-results-total]');
		var gradeCell = row.querySelector('[data-slms-results-grade]');
		var gradeScaleRoot = row.closest('[data-slms-grade-scale]');
		var gradeScale = null;
		var assignmentsReady = '1' === row.getAttribute('data-slms-results-assignments-ready');
		var requiresFinalExam = '1' === row.getAttribute('data-slms-results-requires-final-exam');

		if (!editButton || !totalCell) {
			return;
		}

		if (gradeScaleRoot) {
			try {
				gradeScale = JSON.parse(gradeScaleRoot.getAttribute('data-slms-grade-scale') || 'null');
			} catch (error) {
				gradeScale = null;
			}
		}

		function readNumber(node) {
			var value = node ? parseFloat(node.value || node.getAttribute('data-slms-results-assignment-total') || '0') : 0;
			return isNaN(value) ? 0 : value;
		}

		function hasAssignmentOverrideValue() {
			return assignmentInput && '' !== String(assignmentInput.value || '').trim();
		}

		function readAssignmentTotal() {
			if (hasAssignmentOverrideValue()) {
				return readNumber(assignmentInput);
			}

			var calculated = assignmentInput ? parseFloat(assignmentInput.getAttribute('data-slms-results-calculated-assignment-total') || '0') : 0;
			return isNaN(calculated) ? 0 : calculated;
		}

		function hasFinalExamValue() {
			if (!finalExamInput) {
				return true;
			}

			return '' !== String(finalExamInput.value || '').trim();
		}

		function resolveLetter(total) {
			var bands = gradeScale && Array.isArray(gradeScale.bands) ? gradeScale.bands : [];

			for (var index = 0; index < bands.length; index += 1) {
				var band = bands[index];
				var min = parseFloat(band.min);
				var max = parseFloat(band.max);

				if (isNaN(min) || isNaN(max)) {
					continue;
				}

				if (total >= min && total <= max) {
					return band.label || '';
				}
			}

			for (var fallbackIndex = 0; fallbackIndex < bands.length; fallbackIndex += 1) {
				var fallbackBand = bands[fallbackIndex];
				var fallbackMin = parseFloat(fallbackBand.min);

				if (!isNaN(fallbackMin) && total >= fallbackMin) {
					return fallbackBand.label || '';
				}
			}

			return bands.length ? (bands[bands.length - 1].label || '') : '';
		}

		function refreshTotal() {
			var assignmentTotal = readAssignmentTotal();
			var attendanceTotal = readNumber(attendanceInput);
			var finalExamTotal = readNumber(finalExamInput);
			var nextTotal = assignmentTotal + attendanceTotal + finalExamTotal;
			var canShowTotal = (assignmentsReady || hasAssignmentOverrideValue()) && (!requiresFinalExam || hasFinalExamValue());

			totalCell.textContent = canShowTotal ? nextTotal.toFixed(2) : '-';

			if (gradeCell) {
				gradeCell.textContent = canShowTotal ? resolveLetter(nextTotal) : '-';
			}
		}

		[attendanceInput, assignmentInput, finalExamInput].forEach(function (input) {
			if (!input) {
				return;
			}

			input.addEventListener('input', refreshTotal);
		});

		editButton.addEventListener('click', function (event) {
			if ('submit' === editButton.type) {
				return;
			}

			event.preventDefault();

			[attendanceInput, assignmentInput, finalExamInput].forEach(function (input) {
				if (input) {
					input.disabled = false;
				}
			});

			editButton.textContent = 'Save';
			editButton.type = 'submit';
			refreshTotal();
		});
	});
}


function initAttendanceReviewCollapsibles() {
	var details = Array.prototype.slice.call(document.querySelectorAll('[data-slms-attendance-mobile-collapsible]'));

	if (!details.length || !window.matchMedia) {
		return;
	}

	var media = window.matchMedia('(max-width: 760px)');

	function sync() {
		details.forEach(function (item) {
			if (!item.dataset.slmsDesktopDefaultOpen) {
				item.dataset.slmsDesktopDefaultOpen = item.hasAttribute('open') ? '1' : '0';
			}

			if (media.matches) {
				if ('1' !== item.dataset.slmsMobileInitialized) {
					item.removeAttribute('open');
					item.dataset.slmsMobileInitialized = '1';
				}
			} else if ('1' === item.dataset.slmsDesktopDefaultOpen) {
				item.setAttribute('open', 'open');
			}
		});
	}

	sync();

	if (media.addEventListener) {
		media.addEventListener('change', sync);
	} else if (media.addListener) {
		media.addListener(sync);
	}
}

function initSubjectFileRepeaters(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-file-repeater]').forEach(function (repeater) {
		if ('1' === repeater.dataset.slmsFileRepeaterBound) {
			return;
		}

		repeater.dataset.slmsFileRepeaterBound = '1';

		var list = repeater.querySelector('[data-slms-file-repeater-list]');
		var addButton = repeater.querySelector('[data-slms-file-repeater-add]');
		var fieldName = repeater.getAttribute('data-field-name') || '';
		var accept = repeater.getAttribute('data-accept') || '';

		if (!list || !addButton || !fieldName) {
			return;
		}

		function getInputs() {
			return Array.prototype.slice.call(list.querySelectorAll('[data-slms-file-input]'));
		}

		function getLastInput() {
			var inputs = getInputs();
			return inputs.length ? inputs[inputs.length - 1] : null;
		}

		function updateAddButton() {
			var lastInput = getLastInput();
			var hasFile = !!(lastInput && lastInput.files && lastInput.files.length);
			addButton.hidden = !hasFile;
		}

		function bindInput(input) {
			if (!input) {
				return;
			}

			input.addEventListener('change', updateAddButton);
		}

		function appendInput() {
			var row = document.createElement('label');
			var input = document.createElement('input');

			row.className = 'slms-file-repeater-row';
			input.type = 'file';
			input.name = fieldName + '[]';
			input.setAttribute('data-slms-file-input', '');

			if (accept) {
				input.setAttribute('accept', accept);
			}

			row.appendChild(input);
			list.appendChild(row);
			bindInput(input);
			addButton.hidden = true;
			input.focus();
		}

		getInputs().forEach(bindInput);
		updateAddButton();

		addButton.addEventListener('click', function (event) {
			event.preventDefault();
			appendInput();
		});
	});
}

function initSubjectEmbedUrlRepeaters(root) {
	var scope = root && root.querySelectorAll ? root : document;

	scope.querySelectorAll('[data-slms-embed-url-repeater]').forEach(function (repeater) {
		if ('1' === repeater.dataset.slmsEmbedUrlRepeaterBound) {
			return;
		}

		repeater.dataset.slmsEmbedUrlRepeaterBound = '1';

		var list = repeater.querySelector('[data-slms-embed-url-repeater-list]');
		var addButton = repeater.querySelector('[data-slms-embed-url-add]');
		var maxItems = parseInt(repeater.getAttribute('data-max-items') || '10', 10);
		var removeLabel = repeater.getAttribute('data-remove-label') || 'Remove';

		if (!list || !addButton) {
			return;
		}

		function getRows() {
			return Array.prototype.slice.call(list.querySelectorAll('[data-slms-embed-url-row]'));
		}

		function syncControls() {
			var rows = getRows();
			var canRemove = rows.length > 1;

			rows.forEach(function (row) {
				var removeButton = row.querySelector('[data-slms-embed-url-remove]');
				if (removeButton) {
					removeButton.hidden = !canRemove;
				}
			});

			addButton.hidden = rows.length >= maxItems;
		}

		function appendRow() {
			if (getRows().length >= maxItems) {
				return;
			}

			var row = document.createElement('div');
			var input = document.createElement('input');
			var removeButton = document.createElement('button');

			row.className = 'slms-embed-url-repeater-row';
			row.setAttribute('data-slms-embed-url-row', '');

			input.type = 'url';
			input.name = 'embed_urls[]';
			input.placeholder = 'https://';
			input.setAttribute('data-slms-embed-url-input', '');

			removeButton.type = 'button';
			removeButton.className = 'slms-portal-button is-secondary slms-embed-url-repeater-remove';
			removeButton.setAttribute('data-slms-embed-url-remove', '');
			removeButton.textContent = removeLabel;

			row.appendChild(input);
			row.appendChild(removeButton);
			list.appendChild(row);
			syncControls();
			input.focus();
		}

		addButton.addEventListener('click', appendRow);
		list.addEventListener('click', function (event) {
			var removeButton = event.target && event.target.closest ? event.target.closest('[data-slms-embed-url-remove]') : null;

			if (!removeButton || !list.contains(removeButton)) {
				return;
			}

			var row = removeButton.closest('[data-slms-embed-url-row]');
			if (row && getRows().length > 1) {
				row.remove();
				syncControls();
			}
		});

		syncControls();
	});
}


	// Card Issuer: select/deselect all visible issue checkboxes.
	document.addEventListener('change', function (event) {
		var toggle = event.target && event.target.closest ? event.target.closest('[data-slms-card-issuer-select-all]') : null;
		if (!toggle) { return; }
		var form = toggle.closest('form');
		if (!form) { return; }
		form.querySelectorAll('input[type="checkbox"][name="user_ids[]"]').forEach(function (checkbox) {
			checkbox.checked = toggle.checked;
		});
	});


function initAssignmentAjaxGrading(root) {
	var scope = root && root.querySelectorAll ? root : document;
	var config = window.slmsPortalConfig || {};

	if (!config.ajaxUrl || !window.fetch || !window.FormData) {
		return;
	}

	scope.querySelectorAll('[data-slms-assignment-grade-form]').forEach(function (form) {
		if ('1' === form.dataset.slmsAjaxGradeBound) {
			return;
		}

		form.dataset.slmsAjaxGradeBound = '1';

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var button = form.querySelector('[data-slms-assignment-grade-button]');
			var message = form.querySelector('[data-slms-assignment-grade-message]');
			var card = form.closest('[data-slms-submission-review-card]');
			var originalLabel = button ? button.textContent : '';
			var savingLabel = button && button.dataset.savingLabel ? button.dataset.savingLabel : 'Saving...';
			var formData = new FormData(form);

			if (!formData.get('action')) {
				formData.append('action', 'slms_grade_assignment_submission');
			}

			if (button) {
				button.disabled = true;
				button.textContent = savingLabel;
			}

			showAssignmentGradeMessage(message, '', '');

			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
				.then(function (response) {
					return response.json().catch(function () {
						throw new Error('The server returned an invalid response.');
					});
				})
				.then(function (payload) {
					if (!payload || !payload.success) {
						var errorMessage = payload && payload.data && payload.data.message ? payload.data.message : 'The grade could not be saved.';
						throw new Error(errorMessage);
					}

					var data = payload.data || {};
					updateAssignmentReviewCard(card, data);
					showAssignmentGradeMessage(message, data.message || 'Submission feedback saved successfully.', 'success');
				})
				.catch(function (error) {
					showAssignmentGradeMessage(message, error && error.message ? error.message : 'The grade could not be saved.', 'error');
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
						button.textContent = originalLabel;
					}
				});
		});
	});
}

function showAssignmentGradeMessage(message, text, type) {
	if (!message) {
		return;
	}

	message.textContent = text || '';
	message.classList.remove('is-success', 'is-error');

	if (!text) {
		message.hidden = true;
		return;
	}

	if ('success' === type) {
		message.classList.add('is-success');
	} else if ('error' === type) {
		message.classList.add('is-error');
	}

	message.hidden = false;
}

function updateAssignmentReviewCard(card, data) {
	if (!card || !data) {
		return;
	}

	['slms-submission-review-card--graded', 'slms-submission-review-card--late', 'slms-submission-review-card--ungraded'].forEach(function (className) {
		card.classList.remove(className);
	});

	if (data.card_class) {
		card.classList.add(data.card_class);
	}

	if (data.state) {
		card.setAttribute('data-slms-submission-state', data.state);
	}

	var form = card.querySelector('[data-slms-assignment-grade-form]');
	var scoreInput = form ? form.querySelector('input[name="score"]') : null;

	if (scoreInput && null !== data.score && 'undefined' !== typeof data.score) {
		scoreInput.value = data.score;
	}

	var statusLabel = card.querySelector('[data-slms-submission-status-label]');

	if (statusLabel) {
		statusLabel.textContent = data.display_status || data.status || statusLabel.textContent;
		['is-graded', 'is-late', 'is-ungraded'].forEach(function (className) {
			statusLabel.classList.remove(className);
		});
		if (data.badge_class) {
			statusLabel.classList.add(data.badge_class);
		}
	}
}



function initBroadcastModals() {
	var openButtons = document.querySelectorAll('[data-slms-broadcast-open]');
	var closeButtons = document.querySelectorAll('[data-slms-broadcast-close]');
	var ackButtons = document.querySelectorAll('[data-slms-broadcast-acknowledge]');

	function openModal(modal) {
		if (!modal) {
			return;
		}
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('slms-broadcast-modal-open');
		markBroadcastSeen(modal.getAttribute('data-slms-broadcast-id'), '1' === modal.getAttribute('data-slms-login-popup'));
		var close = modal.querySelector('[data-slms-broadcast-close], [data-slms-broadcast-acknowledge]');
		if (close) {
			window.setTimeout(function () { close.focus(); }, 30);
		}
	}

	function closeModal(modal) {
		if (!modal) {
			return;
		}
		if ('1' === modal.getAttribute('data-slms-login-popup') && '1' === modal.getAttribute('data-slms-requires-ack')) {
			return;
		}
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
		if (!document.querySelector('[data-slms-broadcast-modal].is-open')) {
			document.body.classList.remove('slms-broadcast-modal-open');
		}
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			openModal(document.getElementById(button.getAttribute('data-slms-broadcast-open')));
		});
	});

	closeButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			closeModal(button.closest('[data-slms-broadcast-modal]'));
		});
	});

	ackButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			var modal = button.closest('[data-slms-broadcast-modal]');
			var broadcastId = button.getAttribute('data-slms-broadcast-acknowledge');
			button.disabled = true;
			button.textContent = 'Saving...';
			postBroadcastAction('slms_broadcast_acknowledge', broadcastId, true).then(function () {
				button.textContent = 'Acknowledged';
				if (modal) {
					modal.setAttribute('data-slms-requires-ack', '0');
					modal.classList.remove('needs-ack');
					modal.classList.remove('is-open');
					modal.setAttribute('aria-hidden', 'true');
				}
				document.body.classList.remove('slms-broadcast-modal-open');
			}).catch(function () {
				button.disabled = false;
				button.textContent = 'I Acknowledge';
			});
		});
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' !== event.key) {
			return;
		}
		document.querySelectorAll('[data-slms-broadcast-modal].is-open').forEach(function (modal) {
			closeModal(modal);
		});
	});

	if (window.slmsBroadcastLoginPopupId) {
		openModal(document.getElementById(window.slmsBroadcastLoginPopupId));
	}
}

function markBroadcastSeen(broadcastId, popup) {
	if (!broadcastId) {
		return;
	}
	postBroadcastAction('slms_broadcast_mark_seen', broadcastId, popup).catch(function () {});
}

function postBroadcastAction(action, broadcastId, popup) {
	var config = window.slmsPortalConfig || {};
	if (!config.ajaxUrl || !config.nonce) {
		return Promise.reject();
	}
	var body = new URLSearchParams();
	body.append('action', action);
	body.append('nonce', config.nonce);
	body.append('broadcast_id', broadcastId);
	if (popup) {
		body.append('popup', '1');
	}
	return fetch(config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
		},
		body: body.toString()
	}).then(function (response) {
		if (!response.ok) {
			throw new Error('Request failed');
		}
		return response.json();
	}).then(function (payload) {
		if (!payload || !payload.success) {
			throw new Error('Request failed');
		}
		return payload;
	});
}
