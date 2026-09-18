/**
 * Biometric Gate — frontend lockout overlay, gesture-anchored raw camera capture, rolling
 * re-verification timer, and kill-switch.
 *
 * This does NOT use the FaceIO fio.js widget: enrollment is admin-driven (an ID photo
 * uploaded in wp-admin), and FaceIO's client SDK has no way to accept a static reference
 * image — its enroll()/authenticate() pair only ever compares against faces enrolled live,
 * in-browser, on FaceIO's own facialId system. Verifying a live scan against an admin-
 * uploaded photo instead requires a real image, so this captures a plain camera frame and
 * hands it to our own backend, which calls FaceIO's stateless `faceverify` REST endpoint
 * server-to-server (see class-bg-verification.php). The browser never decides "verified".
 */
(function () {
	'use strict';

	if (typeof window.BiometricGateConfig === 'undefined') {
		return;
	}

	var config = window.BiometricGateConfig;
	var root = document.getElementById('bg-gate-root');
	var isBlockingShell = !!root && root.dataset.bgMode === 'verify';

	if (root && root.dataset.bgMode && 'verify' !== root.dataset.bgMode) {
		return;
	}

	var overlayEl, videoEl, canvasEl, statusEl, startBtn;
	var currentTicket = null;
	var retryCount = 0;
	var intentionalHide = false;
	var rescheduleTimer = null;
	var reconnectPollTimer = null;
	var pausedMedia = [];
	var activeStream = null;

	var MAX_RETRIES = 3;
	var VIRTUAL_CAMERA_PATTERN = /virtual|obs|software engine|splitcam|manycam|vcam|xsplit|epoccam|iripe/i;
	var CAPTURE_WIDTH = 320;
	var CAPTURE_HEIGHT = 240;

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		buildOverlayScaffold();
		observeTampering();
		runScanCycle();
	}

	// ---------------------------------------------------------------------
	// Overlay DOM
	// ---------------------------------------------------------------------

	function buildOverlayScaffold() {
		overlayEl = document.createElement('div');
		overlayEl.id = 'bg-gate-overlay';
		overlayEl.setAttribute('role', 'dialog');
		overlayEl.setAttribute('aria-modal', 'true');
		overlayEl.hidden = true;

		overlayEl.innerHTML =
			'<div class="bg-gate-card">' +
			'<h2>' + config.i18n.startScan + '</h2>' +
			'<p class="bg-gate-status" aria-live="polite"></p>' +
			'<div class="bg-gate-video-frame"><video playsinline autoplay muted></video></div>' +
			'<button type="button" class="bg-gate-start-btn">' + config.i18n.startScan + '</button>' +
			(config.isAdmin ? '<button type="button" class="bg-gate-dev-bypass-btn" style="margin-top:10px; background:#d63638;">Admin Dev Bypass</button>' : '') +
			'</div>';

		document.body.appendChild(overlayEl);

		videoEl = overlayEl.querySelector('video');
		statusEl = overlayEl.querySelector('.bg-gate-status');
		startBtn = overlayEl.querySelector('.bg-gate-start-btn');

		canvasEl = document.createElement('canvas');
		canvasEl.width = CAPTURE_WIDTH;
		canvasEl.height = CAPTURE_HEIGHT;

		startBtn.addEventListener('click', onStartActionClick);

		if (config.isAdmin) {
			var bypassBtn = overlayEl.querySelector('.bg-gate-dev-bypass-btn');
			if (bypassBtn) {
				bypassBtn.addEventListener('click', function () {
					startBtn.disabled = true;
					bypassBtn.disabled = true;
					setStatus('Bypassing...');
					apiPost('/scan/dev-bypass', { page_title: config.pageTitle, page_url: config.pageUrl })
						.then(handleScanSuccess)
						.catch(function (err) {
							startBtn.disabled = false;
							bypassBtn.disabled = false;
							setStatus('Bypass failed: ' + err.message);
						});
				});
			}
		}
	}

	function showOverlay() {
		overlayEl.hidden = false;
		setStatus('');
		startBtn.disabled = false;
		startBtn.hidden = false;

		var bypassBtn = overlayEl.querySelector('.bg-gate-dev-bypass-btn');
		if (bypassBtn) {
			bypassBtn.disabled = false;
		}

		if (!isBlockingShell) {
			document.documentElement.classList.add('bg-gate-blur-active');
			pauseAllMedia();
		}
	}

	function hideOverlay() {
		intentionalHide = true;
		overlayEl.hidden = true;
		document.documentElement.classList.remove('bg-gate-blur-active');
		resumeAllMedia(); // Restore media state
		if (activeStream) {
			stopStream(activeStream);
			activeStream = null;
		}
		window.setTimeout(function () {
			intentionalHide = false;
		}, 0);
	}

	function setStatus(text, isHtml) {
		if (isHtml) {
			statusEl.innerHTML = text;
		} else {
			statusEl.textContent = text;
		}
	}

	function observeTampering() {
		var observer = new MutationObserver(function () {
			if (intentionalHide || !overlayEl) {
				return;
			}
			var stillPresent = document.body.contains(overlayEl);
			var stillVisible = stillPresent && 'none' !== window.getComputedStyle(overlayEl).display && !overlayEl.hidden;

			if (!overlayEl.hidden && (!stillPresent || !stillVisible)) {
				killSwitch('overlay_tampered');
			}
		});

		observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden', 'class'] });
	}

	function pauseAllMedia() {
		pausedMedia = [];
		var mediaElements = document.querySelectorAll('video, audio, presto-player, presto-youtube, presto-vimeo, presto-video, presto-audio, presto-bunny');
		mediaElements.forEach(function (el) {
			try {
				var isPlaying = (el.currentTime > 0 && !el.paused && !el.ended && el.readyState > 2);
				var isPrestoPlaying = el.classList && (el.classList.contains('plyr--playing') || el.querySelector('.plyr--playing'));
				if (isPlaying || isPrestoPlaying) {
					pausedMedia.push(el);
				}
				if (typeof el.pause === 'function') {
					el.pause();
				}
			} catch (e) { /* noop */ }
		});
		document.querySelectorAll('iframe').forEach(function (frame) {
			try {
				frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }), '*');
				frame.contentWindow.postMessage(JSON.stringify({ method: 'pause' }), '*');
			} catch (e) { /* skip */ }
		});
	}

	function resumeAllMedia() {
		pausedMedia.forEach(function (el) {
			try {
				if (typeof el.play === 'function') {
					el.play();
				}
			} catch (e) { /* noop */ }
		});
		pausedMedia = [];
	}

	// ---------------------------------------------------------------------
	// Scan lifecycle
	// ---------------------------------------------------------------------

	function runScanCycle() {
		apiPost('/scan/start', { page_title: config.pageTitle, page_url: config.pageUrl })
			.then(function (res) {
				if (res.bypass) {
					if (isBlockingShell) {
						window.location.reload();
						return;
					}
					hideOverlay();
					scheduleNextCheck(res.seconds_until_rescan || config.scanThresholdSec);
					return;
				}

				currentTicket = res.ticket;
				retryCount = 0;
				showOverlay();
			})
			.catch(function () {
				showConnectionLost();
			});
	}

	function onStartActionClick() {
		if (!config.hasEnrollment) {
			// Content-guard normally intercepts this case with a static "not-enrolled" shell
			// before this script ever runs; this is just a defensive fallback.
			setStatus(config.i18n.noEnrollment || 'No biometric profile is on file. Contact your administrator.');
			return;
		}

		startBtn.disabled = true;

		if (activeStream) {
			captureAndSubmit(activeStream, false);
		} else {
			setStatus(config.i18n.verifying || "Starting camera...");
			navigator.mediaDevices.getUserMedia({ video: buildVideoConstraints() })
				.then(auditDevicesThenCapture)
				.catch(handleCameraError);
		}
	}

	function buildVideoConstraints() {
		var preferred = null;
		try {
			preferred = window.localStorage.getItem('bg_preferred_camera_id');
		} catch (e) { /* localStorage unavailable — fall back to default camera. */ }

		return preferred ? { deviceId: { exact: preferred } } : true;
	}

	/**
	 * Device-label auditing runs *after* getUserMedia grants permission, not before: browsers
	 * withhold device labels entirely pre-permission, so every legitimate first-time visitor
	 * would show empty labels and false-positive a kill-switch if audited first.
	 */
	function auditDevicesThenCapture(stream) {
		return navigator.mediaDevices.enumerateDevices().then(function (devices) {
			var videoInputs = devices.filter(function (d) { return 'videoinput' === d.kind; });

			var suspicious = videoInputs.some(function (d) {
				var label = (d.label || '').trim().toLowerCase();
				return '' === label || VIRTUAL_CAMERA_PATTERN.test(label);
			});

			if (suspicious) {
				stopStream(stream);
				killSwitch('virtual_camera_detected');
				return;
			}

			activeStream = stream;
			return captureAndSubmit(stream, true);
		});
	}

	function captureAndSubmit(stream, isFirstTime) {
		if (isFirstTime) {
			videoEl.srcObject = stream;
		}

		return new Promise(function (resolve) {
			var takePicture = function() {
				setStatus(config.i18n.verifying || "Verifying...");
				var ctx = canvasEl.getContext('2d');
				ctx.drawImage(videoEl, 0, 0, CAPTURE_WIDTH, CAPTURE_HEIGHT);
				var dataUrl = canvasEl.toDataURL('image/jpeg', 0.85);
				var base64 = dataUrl.split(',')[1] || '';

				resolve(
					apiPost('/scan/result', {
						ticket: currentTicket,
						frame: base64,
						page_title: config.pageTitle,
						page_url: config.pageUrl,
					}).then(handleScanSuccess).catch(handleScanError)
				);
			};

			if (isFirstTime) {
				videoEl.onloadeddata = function () {
					var countdown = 3;
					setStatus("Align your face... " + countdown);
					var interval = setInterval(function() {
						countdown--;
						if (countdown > 0) {
							setStatus("Align your face... " + countdown);
						} else {
							clearInterval(interval);
							takePicture();
						}
					}, 1000);
				};
			} else {
				takePicture();
			}
		});
	}

	function handleCameraError(err) {
		var name = err && err.name ? err.name : '';

		if ('NotFoundError' === name || 'OverconstrainedError' === name) {
			setStatus(config.noCameraMessage || config.i18n.noCamera, true);
			startBtn.disabled = false;
			return;
		}

		// Permission denied, or any other getUserMedia failure — counts as a failed attempt,
		// same as a failed face match, so a student can't dodge verification by repeatedly
		// declining the camera prompt.
		handleScanError({ code: 'bg_camera_denied', message: config.i18n.scanFailed });
	}

	function stopStream(stream) {
		if (stream && stream.getTracks) {
			stream.getTracks().forEach(function (track) { track.stop(); });
		}
	}

	function handleScanSuccess(res) {
		retryCount = 0;
		hideOverlay();

		if (isBlockingShell) {
			window.location.reload();
			return;
		}

		scheduleNextCheck(res.seconds_until_rescan || config.scanThresholdSec);
	}

	function handleScanError(err) {
		if (err && 'bg_invalid_ticket' === err.code) {
			runScanCycle();
			return;
		}

		retryCount += 1;

		if (retryCount >= MAX_RETRIES) {
			killSwitch('max_retries_exceeded');
			return;
		}

		var displayMsg = config.i18n.scanFailed;
		var isHtml = false;

		if (err && err.isCameraError) {
			displayMsg = err.message;
			isHtml = true;
		} else if (err && err.message) {
			displayMsg = err.message;
		}

		if (err && err.isCameraError) {
			// Don't append retry counter for camera hardware errors
			setStatus(displayMsg, isHtml);
		} else {
			setStatus(displayMsg + ' (' + retryCount + '/' + MAX_RETRIES + ')', isHtml);
		}

		startBtn.disabled = false;

		apiPost('/scan/start', { page_title: config.pageTitle, page_url: config.pageUrl }).then(function (res) {
			if (!res.bypass) {
				currentTicket = res.ticket;
			}
		});
	}

	function scheduleNextCheck(seconds) {
		if (rescheduleTimer) {
			window.clearTimeout(rescheduleTimer);
		}
		try {
			window.localStorage.setItem('bg_expires_at', String(Date.now() + seconds * 1000));
		} catch (e) { /* noop */ }
		rescheduleTimer = window.setTimeout(runScanCycle, Math.max(1000, seconds * 1000));
	}

	function showConnectionLost() {
		showOverlay();
		startBtn.hidden = true;
		setStatus(config.i18n.connectionLost);

		if (!reconnectPollTimer) {
			window.addEventListener('online', onConnectionRestored, { once: true });
			reconnectPollTimer = window.setInterval(function () {
				if (navigator.onLine) {
					onConnectionRestored();
				}
			}, 5000);
		}
	}

	function onConnectionRestored() {
		if (reconnectPollTimer) {
			window.clearInterval(reconnectPollTimer);
			reconnectPollTimer = null;
		}
		startBtn.hidden = false;
		runScanCycle();
	}

	function killSwitch(reason) {
		if (activeStream) {
			stopStream(activeStream);
			activeStream = null;
		}
		var redirected = false;
		var goHome = function () {
			if (redirected) {
				return;
			}
			redirected = true;
			window.location.href = '/';
		};
		apiPost('/session/killswitch', { reason: reason }).then(goHome).catch(goHome);
		window.setTimeout(goHome, 3000);
	}

	function apiPost(path, body) {
		return fetch(config.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify(body || {}),
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (json) {
				if (!response.ok) {
					var err = new Error(json.message || 'Request failed');
					err.code = json.code;
					err.status = response.status;
					throw err;
				}
				return json;
			});
		});
	}
})();
