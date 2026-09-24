(function () {
	'use strict';

	function downloadDataUrl(dataUrl, filename) {
		var link = document.createElement('a');
		link.download = (filename || 'ugp-id-card') + '.png';
		link.href = dataUrl;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	}

	function downloadCanvasAsPng(canvas, filename) {
		downloadDataUrl(canvas.toDataURL('image/png'), filename);
	}

	function dataUrlToBase64(dataUrl) {
		return (dataUrl || '').split(',')[1] || '';
	}

	function downloadBlob(blob, filename) {
		var url = window.URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.download = filename || 'issued-id-cards.zip';
		link.href = url;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(function () {
			window.URL.revokeObjectURL(url);
		}, 1000);
	}

	function fitText(root) {
		(root || document).querySelectorAll('.ugp-id-fit-text').forEach(function (node) {
			if (node.dataset.ugpFitDone) {
				return;
			}
			node.dataset.ugpFitDone = '1';
			var style = window.getComputedStyle(node);
			var fontSize = parseFloat(style.fontSize);
			var minSize = parseFloat(node.dataset.minFontSize || 7);
			while (fontSize > minSize && (node.scrollWidth > node.clientWidth || node.scrollHeight > node.clientHeight + 1)) {
				fontSize -= 0.25;
				node.style.fontSize = fontSize + 'px';
			}
		});
	}

	function normalizeCanvasSize(sourceCanvas, targetWidth, targetHeight) {
		var width = parseInt(targetWidth || sourceCanvas.width, 10);
		var height = parseInt(targetHeight || sourceCanvas.height, 10);
		if (!width || !height || (sourceCanvas.width === width && sourceCanvas.height === height)) {
			return sourceCanvas;
		}
		var exportCanvas = document.createElement('canvas');
		exportCanvas.width = width;
		exportCanvas.height = height;
		var ctx = exportCanvas.getContext('2d');
		ctx.drawImage(sourceCanvas, 0, 0, width, height);
		return exportCanvas;
	}

	function waitForImages(root) {
		var images = Array.prototype.slice.call((root || document).querySelectorAll('img'));
		return Promise.all(images.map(function (img) {
			if (img.complete) {
				return Promise.resolve();
			}
			return new Promise(function (resolve) {
				img.addEventListener('load', resolve, { once: true });
				img.addEventListener('error', resolve, { once: true });
			});
		}));
	}

	function waitForFonts() {
		if (document.fonts && document.fonts.ready) {
			return document.fonts.ready.catch(function () {});
		}
		return Promise.resolve();
	}


	function markCardSetState(card, state) {
		var set = card ? card.closest('[data-ugp-id-card-set]') : null;
		if (!set) {
			return;
		}
		if (state === 'error') {
			set.classList.add('is-ugp-flattened-error');
			set.classList.remove('is-ugp-flattened-ready');
			return;
		}
		var cards = Array.prototype.slice.call(set.querySelectorAll('.ugp-id-card'));
		if (cards.length && cards.every(function (item) { return item.dataset.ugpFlattened === '1'; })) {
			set.classList.add('is-ugp-flattened-ready');
			set.classList.remove('is-ugp-flattened-error');
		}
	}

	function renderCardToCanvas(card) {
		if (!card || !window.html2canvas) {
			return Promise.reject(new Error('html2canvas is unavailable.'));
		}
		if (card.dataset.ugpFlattened === '1' && card._ugpFlattenCanvas) {
			return Promise.resolve(card._ugpFlattenCanvas);
		}

		var exportWidth = parseInt(card.getAttribute('data-export-width') || '638', 10);
		var exportHeight = parseInt(card.getAttribute('data-export-height') || '1011', 10);
		var scale = Math.max(exportWidth / Math.max(card.offsetWidth, 1), exportHeight / Math.max(card.offsetHeight, 1), 2);

		fitText(card);
		return Promise.all([waitForFonts(), waitForImages(card)]).then(function () {
			return window.html2canvas(card, {
				backgroundColor: null,
				useCORS: true,
				allowTaint: false,
				scale: scale,
				logging: false,
				onclone: function (clonedDoc) {
					var clone = clonedDoc.getElementById(card.id);
					if (clone) {
						clone.classList.remove('is-flattening');
						clone.style.boxShadow = 'none';
						clone.style.borderRadius = '0';
						clone.style.visibility = 'visible';
						clone.style.opacity = '1';
						var cloneSet = clone.closest('[data-ugp-id-card-set]');
						if (cloneSet) {
							cloneSet.classList.add('is-ugp-flattened-ready');
							cloneSet.classList.remove('is-ugp-flattened-error');
						}
					}
				}
			});
		}).then(function (canvas) {
			var normalized = normalizeCanvasSize(canvas, exportWidth, exportHeight);
			card._ugpFlattenCanvas = normalized;
			return normalized;
		});
	}

	function flattenCard(card) {
		if (!card || card.dataset.ugpFlattened === '1') {
			return Promise.resolve(card);
		}
		return renderCardToCanvas(card).then(function (canvas) {
			var dataUrl = canvas.toDataURL('image/png');
			card.dataset.ugpFlattened = '1';
			card.dataset.ugpFlattenedSrc = dataUrl;
			card.innerHTML = '';
			var img = document.createElement('img');
			img.className = 'ugp-id-flattened-image';
			img.alt = 'UGP ID card';
			img.src = dataUrl;
			card.appendChild(img);
			markCardSetState(card, 'ready');
			return card;
		}).catch(function (error) {
			console.error('UGP ID flattening failed:', error);
			markCardSetState(card, 'error');
			return card;
		});
	}

	function flattenCards(root) {
		var scope = root || document;
		if (!window.html2canvas) {
			Array.prototype.slice.call(scope.querySelectorAll('.ugp-id-card-set.ugp-id-flattened-only')).forEach(function (set) {
				set.classList.add('is-ugp-flattened-error');
			});
			return Promise.resolve([]);
		}
		var cards = Array.prototype.slice.call(scope.querySelectorAll('.ugp-id-card'));
		return Promise.all(cards.map(flattenCard));
	}

	function cardDataUrl(card) {
		if (!card) {
			return Promise.reject(new Error('Card not found.'));
		}
		if (card.dataset.ugpFlattenedSrc) {
			return Promise.resolve(card.dataset.ugpFlattenedSrc);
		}
		return flattenCard(card).then(function () {
			return card.dataset.ugpFlattenedSrc || '';
		});
	}

	function buildPrintableHtml(cards) {
		var styles = '<style>@page{size:54mm 85.6mm;margin:0;}html,body{margin:0;padding:0;} .card-page{width:54mm;height:85.6mm;page-break-after:always;break-after:page;} .card-page img{width:54mm;height:85.6mm;display:block;}</style>';
		var body = cards.map(function (card) {
			var src = card.dataset.ugpFlattenedSrc || (card.querySelector('.ugp-id-flattened-image') ? card.querySelector('.ugp-id-flattened-image').src : '');
			return '<div class="card-page"><img src="' + src + '" alt="UGP ID card"></div>';
		}).join('');
		return '<!doctype html><html><head><meta charset="utf-8"><title>UGP ID Cards</title>' + styles + '</head><body>' + body + '</body></html>';
	}

	function setupDownloads(root) {
		(root || document).querySelectorAll('[data-ugp-id-download]').forEach(function (button) {
			if (button.dataset.ugpBound) {
				return;
			}
			button.dataset.ugpBound = '1';
			button.addEventListener('click', function () {
				var id = button.getAttribute('data-target');
				var target = id ? document.getElementById(id) : null;
				if (!target) {
					return;
				}
				button.disabled = true;
				var oldText = button.textContent;
				button.textContent = 'Preparing...';
				cardDataUrl(target).then(function (dataUrl) {
					if (dataUrl) {
						downloadDataUrl(dataUrl, button.getAttribute('data-filename'));
					} else if (target._ugpFlattenCanvas) {
						downloadCanvasAsPng(target._ugpFlattenCanvas, button.getAttribute('data-filename'));
					}
				}).finally(function () {
					button.disabled = false;
					button.textContent = oldText;
				});
			});
		});

		(root || document).querySelectorAll('[data-ugp-id-print]').forEach(function (button) {
			if (button.dataset.ugpBound) {
				return;
			}
			button.dataset.ugpBound = '1';
			button.addEventListener('click', function () {
				var toolbar = button.closest('[data-ugp-id-toolbar]');
				var cardSet = toolbar ? toolbar.nextElementSibling : null;
				if (!cardSet || !cardSet.matches('[data-ugp-id-card-set]')) {
					var scope = button.closest('.ugp-id-preview-header, .ugp-id-portal-card, .slms-id-card-shell, .slms-card-issuer-panel, .wrap') || document;
					cardSet = scope.querySelector('[data-ugp-id-card-set]') || document.querySelector('[data-ugp-id-card-set]');
				}
				var cards = Array.prototype.slice.call((cardSet || document).querySelectorAll('.ugp-id-card'));
				Promise.all(cards.map(flattenCard)).then(function () {
					var printWindow = window.open('', '_blank');
					if (!printWindow) {
						window.print();
						return;
					}
					printWindow.document.open();
					printWindow.document.write(buildPrintableHtml(cards));
					printWindow.document.close();
					printWindow.focus();
					printWindow.onload = function () {
						printWindow.print();
					};
				});
			});
		});

		(root || document).querySelectorAll('[data-ugp-id-select-all]').forEach(function (checkbox) {
			if (checkbox.dataset.ugpBound) {
				return;
			}
			checkbox.dataset.ugpBound = '1';
			checkbox.addEventListener('change', function () {
				var table = checkbox.closest('table');
				if (!table) {
					return;
				}
				table.querySelectorAll('tbody input[type="checkbox"]').forEach(function (item) {
					item.checked = checkbox.checked;
				});
			});
		});
	}


	function parseBatchFilters(button) {
		try {
			return JSON.parse(button.getAttribute('data-filters') || '{}') || {};
		} catch (error) {
			return {};
		}
	}

	function setupBatchDownloads(root) {
		(root || document).querySelectorAll('[data-ugp-id-batch-download]').forEach(function (button) {
			if (button.dataset.ugpBatchBound) {
				return;
			}
			button.dataset.ugpBatchBound = '1';
			button.addEventListener('click', function () {
				if (!window.SLMS_ID_CARD_BATCH || !window.SLMS_ID_CARD_BATCH.ajaxUrl || !window.SLMS_ID_CARD_BATCH.nonce) {
					window.alert('Batch download is not available on this page.');
					return;
				}
				if (!window.JSZip) {
					window.alert('The ZIP library could not be loaded. Please refresh the page and try again.');
					return;
				}

				var oldText = button.textContent;
				button.disabled = true;
				button.textContent = 'Preparing issued cards...';

				var formData = new window.FormData();
				formData.append('action', 'slms_id_cards_batch_render');
				formData.append('nonce', window.SLMS_ID_CARD_BATCH.nonce);
				formData.append('filters', JSON.stringify(parseBatchFilters(button)));

				fetch(window.SLMS_ID_CARD_BATCH.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				}).then(function (response) {
					return response.json();
				}).then(function (payload) {
					if (!payload || !payload.success) {
						throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Unable to prepare issued cards.');
					}

					var cards = payload.data.cards || [];
					if (!cards.length) {
						window.alert('No issued ID cards match the current filters.');
						return null;
					}

					var stage = document.createElement('div');
					stage.className = 'ugp-id-batch-stage';
					stage.innerHTML = cards.map(function (entry) { return entry.html || ''; }).join('');
					document.body.appendChild(stage);
					fitText(stage);

					var zip = new window.JSZip();
					var chain = Promise.resolve();
					cards.forEach(function (entry, index) {
						chain = chain.then(function () {
							button.textContent = 'Flattening ' + (index + 1) + ' of ' + cards.length + '...';
							var front = document.getElementById(entry.front_id);
							var back = document.getElementById(entry.back_id);
							return Promise.all([cardDataUrl(front), cardDataUrl(back)]).then(function (urls) {
								if (urls[0]) {
									zip.file(entry.front_filename || ('card-' + (index + 1) + '-front.png'), dataUrlToBase64(urls[0]), { base64: true });
								}
								if (urls[1]) {
									zip.file(entry.back_filename || ('card-' + (index + 1) + '-back.png'), dataUrlToBase64(urls[1]), { base64: true });
								}
							});
						});
					});

					return chain.then(function () {
						button.textContent = 'Building ZIP...';
						return zip.generateAsync({ type: 'blob' });
					}).then(function (blob) {
						downloadBlob(blob, payload.data.filename || 'issued-id-cards.zip');
						if (payload.data.message) {
							window.alert(payload.data.message);
						}
					}).finally(function () {
						document.body.removeChild(stage);
					});
				}).catch(function (error) {
					window.alert(error.message || 'Unable to download issued cards.');
				}).finally(function () {
					button.disabled = false;
					button.textContent = oldText;
				});
			});
		});
	}

	function replaceSimpleLmsPortalPanel() {
		if (!window.UGP_ID_REPLACE_PORTAL || !window.UGP_ID_REPLACE_PORTAL.enabled) {
			return;
		}
		var panel = document.querySelector('.slms-id-panel');
		if (!panel) {
			return;
		}
		panel.innerHTML = '<p>Loading UGP ID card...</p>';
		fetch(window.UGP_ID_REPLACE_PORTAL.restUrl, {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.UGP_ID_REPLACE_PORTAL.nonce
			}
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Unable to load ID card.');
			}
			return response.json();
		}).then(function (payload) {
			panel.innerHTML = payload.html || '<p>No ID card available.</p>';
			fitText(panel);
			setupDownloads(panel);
			setupBatchDownloads(panel);
			flattenCards(panel);
		}).catch(function (error) {
			panel.innerHTML = '<p>' + error.message + '</p>';
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		fitText(document);
		setupDownloads(document);
		setupBatchDownloads(document);
		flattenCards(document);
		replaceSimpleLmsPortalPanel();
	});
}());
