document.addEventListener('DOMContentLoaded', function () {
	var scopes = {};

	document.querySelectorAll('[data-slms-weight-input]').forEach(function (input) {
		var scope = input.getAttribute('data-slms-weight-scope') || 'default';

		if (!scopes[scope]) {
			scopes[scope] = [];
		}

		scopes[scope].push(input);
		input.addEventListener('input', function () {
			updateScope(scope);
		});
	});

	Object.keys(scopes).forEach(updateScope);
	syncAttendanceRows();
	setupWeekStructureEditors();

	function updateScope(scope) {
		var total = 0;

		(scopes[scope] || []).forEach(function (input) {
			var value = parseFloat(input.value || '0');

			if (!Number.isNaN(value)) {
				total += value;
			}
		});

		document.querySelectorAll('[data-slms-weight-total][data-slms-weight-scope="' + scope + '"]').forEach(function (target) {
			target.textContent = total.toFixed(2);
		});
	}

	function syncAttendanceRows() {
		document.querySelectorAll('[data-slms-attendance-status]').forEach(function (select) {
			var row = select.closest('tr');
			var lateInput = row ? row.querySelector('[data-slms-late-minutes]') : null;

			if (!lateInput) {
				return;
			}

			function syncLateInput() {
				var isLate = select.value === 'late';

				lateInput.disabled = !isLate;

				if (!isLate && (lateInput.value === '' || lateInput.value === '0')) {
					lateInput.value = 0;
				}
			}

			select.addEventListener('change', syncLateInput);
			syncLateInput();
		});
	}

	function setupWeekStructureEditors() {
		document.querySelectorAll('[data-slms-week-structure-editor]').forEach(function (editor) {
			var rows = editor.querySelector('[data-slms-week-rows]');
			var template = editor.querySelector('[data-slms-week-row-template]');
			var addButton = editor.querySelector('[data-slms-add-week]');
			var emptyState = editor.querySelector('[data-slms-week-empty]');

			if (!rows || !template || !addButton) {
				return;
			}

			function syncEmptyState() {
				if (!emptyState) {
					return;
				}

				emptyState.classList.toggle('is-hidden', !!rows.querySelector('[data-slms-week-row]'));
			}

			function getNextIndex() {
				var nextIndex = parseInt(editor.getAttribute('data-next-index') || '0', 10);

				if (Number.isNaN(nextIndex)) {
					nextIndex = rows.querySelectorAll('[data-slms-week-row]').length;
				}

				editor.setAttribute('data-next-index', String(nextIndex + 1));

				return nextIndex;
			}

			function getSuggestedWeekNumber() {
				var maxWeekNumber = 0;

				rows.querySelectorAll('[data-slms-week-number]').forEach(function (input) {
					var weekNumber = parseInt(input.value || '0', 10);

					if (!Number.isNaN(weekNumber) && weekNumber > maxWeekNumber) {
						maxWeekNumber = weekNumber;
					}
				});

				return maxWeekNumber + 1;
			}

			addButton.addEventListener('click', function () {
				var index = getNextIndex();
				var html = template.innerHTML.replace(/__index__/g, String(index)).trim();
				var wrapper = document.createElement('tbody');
				var row;
				var weekNumberInput;

				wrapper.innerHTML = html;
				row = wrapper.querySelector('[data-slms-week-row]');

				if (!row) {
					return;
				}

				weekNumberInput = row.querySelector('[data-slms-week-number]');

				if (weekNumberInput && !weekNumberInput.value) {
					weekNumberInput.value = String(getSuggestedWeekNumber());
				}

				rows.appendChild(row);
				syncEmptyState();

				if (weekNumberInput) {
					weekNumberInput.focus();
				}
			});

			rows.addEventListener('click', function (event) {
				var removeButton = event.target.closest('[data-slms-remove-week]');
				var row;

				if (!removeButton) {
					return;
				}

				row = removeButton.closest('[data-slms-week-row]');

				if (!row) {
					return;
				}

				row.remove();
				syncEmptyState();
			});

			syncEmptyState();
		});
	}
});
