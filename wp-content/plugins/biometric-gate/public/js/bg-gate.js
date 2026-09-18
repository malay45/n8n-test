/**
 * Biometric Gate — frontend lockout overlay, FaceIO widget integration, rolling
 * re-verification timer, and kill-switch. 
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

	var overlayEl, statusEl, startBtn;
	var currentTicket = null;
	var retryCount = 0;
	var intentionalHide = false;
	var rescheduleTimer = null;
	var reconnectPollTimer = null;
	var pausedMedia = [];

	var MAX_RETRIES = 3;

	// Instantiate FaceIO
	var faceio = null;

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		// FaceIO will be instantiated right before the scan to prevent re-verify bugs.
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

		// The button text depends on if they have enrolled yet
		var btnText = config.hasEnrollment ? config.i18n.startScan : "Enroll Face";

		overlayEl.innerHTML =
			'<div class="bg-gate-card">' +
			'<h2>' + btnText + '</h2>' +
			'<p class="bg-gate-status" aria-live="polite"></p>' +
			'<button type="button" class="bg-gate-start-btn">' + btnText + '</button>' +
			(config.isAdmin ? '<button type="button" class="bg-gate-dev-bypass-btn" style="margin-top:10px; background:#d63638;">Admin Dev Bypass</button>' : '') +
			'</div>';

		document.body.appendChild(overlayEl);

		statusEl = overlayEl.querySelector('.bg-gate-status');
		startBtn = overlayEl.querySelector('.bg-gate-start-btn');

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
		if (typeof faceIO === 'undefined') {
			setStatus("FACEIO library failed to load.");
			return;
		}

		// Fresh instance every time fixes the bug where the widget doesn't show on re-verification
		faceio = new faceIO(config.faceioAppId);

		startBtn.disabled = true;
		setStatus(config.i18n.verifying);

		checkVirtualCamera().then(function () {
			if (config.hasEnrollment) {
				doAuthenticate();
			} else {
				setStatus("Registering your face...");
				doEnroll();
			}
		}).catch(function (reason) {
			killSwitch(reason);
		});
	}

	function checkVirtualCamera() {
		return new Promise(function (resolve, reject) {
			if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices || !navigator.mediaDevices.getUserMedia) {
				return resolve();
			}

			// We must request permission first; otherwise modern browsers return empty labels
			navigator.mediaDevices.getUserMedia({ video: true })
				.then(function (stream) {
					navigator.mediaDevices.enumerateDevices().then(function (devices) {
						// Immediately release the camera so FaceIO can use it
						stream.getTracks().forEach(function (track) { track.stop(); });

						var videoDevices = devices.filter(function (d) { return d.kind === 'videoinput'; });
						var suspicious = ['virtual', 'obs', 'software engine', 'splitcam', 'manycam', 'vcam', 'xsplit', 'epoccam', 'iripe'];

						for (var i = 0; i < videoDevices.length; i++) {
							var label = videoDevices[i].label.toLowerCase();

							if (label.trim() === '') {
								// An empty label after permission is granted is heavily indicative of a forged/simulated device in many browsers
								return reject('virtual_camera_empty_signature');
							}

							for (var j = 0; j < suspicious.length; j++) {
								if (label.indexOf(suspicious[j]) !== -1) {
									return reject('virtual_camera_detected');
								}
							}
						}

						resolve();
					}).catch(function () { resolve(); });
				})
				.catch(function () {
					// Camera denied or unavailable. Let FaceIO handle it normally so the user sees the proper error message.
					resolve();
				});
		});
	}

	function doAuthenticate() {
		faceio.authenticate({
			"payload": { "user_id": config.userId }
		}).then(function (userData) {
			// Success! Send facialId to backend.
			apiPost('/scan/result', {
				ticket: currentTicket,
				facialId: userData.facialId,
				page_title: config.pageTitle,
				page_url: config.pageUrl,
			}).then(handleScanSuccess).catch(handleScanError);
		}).catch(function (errCode) {
			handleScanError({
				code: 'faceio_error',
				error: errCode,
				message: getFaceioErrorMessage(errCode),
				isCameraError: (errCode === 1 || errCode === 20)
			});
		});
	}

	function doEnroll() {
		faceio.enroll({
			"payload": { "user_id": config.userId }
		}).then(function (userData) {
			// Success! User is enrolled. Send facialId to backend to save it.
			apiPost('/scan/enroll-front', {
				facialId: userData.facialId
			}).then(function () {
				// Enrollment saved. Now they are verified and enrolled.
				config.hasEnrollment = true;
				handleScanSuccess({ seconds_until_rescan: config.scanThresholdSec });
			}).catch(function (err) {
				handleScanError(err);
			});
		}).catch(function (errCode) {
			handleScanError({
				code: 'faceio_error',
				error: errCode,
				message: getFaceioErrorMessage(errCode),
				isCameraError: (errCode === 1 || errCode === 20)
			});
		});
	}

	function getFaceioErrorMessage(errCode) {
		switch (errCode) {
			case 1:
			case 20:
				return config.noCameraMessage || config.i18n.noCamera;
			case 2: return "No face detected. Please ensure your face is visible.";
			case 3: return "Face data not available / Unrecognized face.";
			case 4: return "Multiple faces detected. Please ensure only one face is in the frame.";
			case 5: return "Face already enrolled.";
			case 6: return "Spoofing attempt detected.";
			case 7: return "Face mismatch.";
			case 10: return "Application unauthorized (Check Domain Whitelisting in FACEIO Console).";
			case 13: return "Session expired. Please try again.";
			case 14: return "Network timeout. Please check your connection.";
			default: return "Face scan failed (Error Code: " + errCode + ").";
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
