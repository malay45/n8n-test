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
 *
 * "Real-time guidance & face normalization" (client QA round 2) is implemented the same
 * way: FaceIO exposes no standalone alignment/normalization hook outside the full fio.js
 * widget flow we deliberately don't use, so instead this requests a higher-resolution
 * camera stream, center-crops the captured frame to the oval guide's aspect ratio (removing
 * background noise), and applies a light contrast/brightness normalization before sending —
 * a real, honest quality improvement rather than a FaceIO capability that doesn't exist here.
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

	var overlayEl, videoEl, canvasEl, statusEl, startBtn, closeBtn;
	var currentTicket = null;
	var retryCount = 0;
	var intentionalHide = false;
	var rescheduleTimer = null;
	var reconnectPollTimer = null;
	var mediaRescanTimer = null;
	var devtoolsCheckTimer = null;
	var pausedMedia = [];
	var activeStream = null;

	var MAX_RETRIES = 3;
	var VIRTUAL_CAMERA_PATTERN = /virtual|obs|software engine|splitcam|manycam|vcam|xsplit|epoccam|iripe|usb video|software stream/i;

	// Final submitted-frame size, portrait-oriented to match the oval guide mask. Bumped up
	// from an earlier 320x240: low capture resolution was a real contributor to match
	// confidence capping in the low 70s. The *source* stream itself is also requested at a
	// higher resolution below (buildVideoConstraints) — enlarging just the canvas without a
	// better source would only upscale, not add real detail.
	var CAPTURE_WIDTH = 480;
	var CAPTURE_HEIGHT = 600;

	var DARKNESS_THRESHOLD = 10; // Bumped slightly to 10, but 40 is too strict.
	var BRIGHTNESS_THRESHOLD = 235; // Overexposure/glare ceiling on the same 0-255 scale.
	var DEVTOOLS_SIZE_THRESHOLD = 160; // px delta between outer/inner window — heuristic, imperfect by nature.
	var MEDIA_RESCAN_INTERVAL_MS = 500; // Re-sweep for newly-added/reinitialized players while locked out.

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		buildOverlayScaffold();
		observeTampering();
		setupInputBlocking();
		startDevtoolsWatch();
		setupIphoneVideoOverride();
		document.addEventListener('visibilitychange', onVisibilityChange);
		window.setInterval(function () {
			if (reconnectPollTimer) return;

			if (!navigator.onLine) {
				showConnectionLost();
				return;
			}

			fetch(config.restUrl + '/session/status', {
				method: 'GET',
				headers: { 'X-WP-Nonce': config.nonce },
				cache: 'no-cache'
			})
				.then(function (res) {
					// Only treat actual network drops as a disconnect, not 403/401 errors.
					if (!res.ok && res.status !== 401 && res.status !== 403 && res.status !== 423) {
						throw new Error('Network down');
					}
				})
				.catch(function () {
					if (!reconnectPollTimer) showConnectionLost();
				});
		}, 5000);
		runScanCycle();
	}

	function killSwitchEnabled(type) {
		return !!(config.killSwitchesEnabled && config.killSwitchesEnabled[type]);
	}

	function setupIphoneVideoOverride() {
		if (!config.forceNativeIos) return;
		var isIphone = /iPhone/i.test(navigator.userAgent) && !/iPad/i.test(navigator.userAgent);
		if (!isIphone) return;

		var reqFs = Element.prototype.requestFullscreen || Element.prototype.webkitRequestFullscreen || Element.prototype.mozRequestFullScreen || Element.prototype.msRequestFullscreen;

		if (reqFs) {
			var overrideFn = function () {
				var video = (this.tagName && this.tagName.toLowerCase() === 'video') ? this : this.querySelector('video');
				if (video && typeof video.webkitEnterFullscreen === 'function') {
					return video.webkitEnterFullscreen();
				}
				return reqFs.apply(this, arguments);
			};
			Element.prototype.requestFullscreen = overrideFn;
			if (Element.prototype.webkitRequestFullscreen) Element.prototype.webkitRequestFullscreen = overrideFn;
			if (Element.prototype.mozRequestFullScreen) Element.prototype.mozRequestFullScreen = overrideFn;
			if (Element.prototype.msRequestFullscreen) Element.prototype.msRequestFullscreen = overrideFn;
		}
	}

	// ---------------------------------------------------------------------
	// Overlay DOM
	// ---------------------------------------------------------------------

	function buildOverlayScaffold() {
		overlayEl = document.createElement('dialog');
		overlayEl.id = 'bg-gate-overlay';

		overlayEl.innerHTML =
			'<button type="button" class="bg-gate-close-btn">' + (config.i18n.closeButton || 'Close') + '</button>' +
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
		closeBtn = overlayEl.querySelector('.bg-gate-close-btn');

		canvasEl = document.createElement('canvas');
		canvasEl.width = CAPTURE_WIDTH;
		canvasEl.height = CAPTURE_HEIGHT;

		startBtn.addEventListener('click', onStartActionClick);
		closeBtn.addEventListener('click', onCloseButtonClick);

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

	/**
	 * Spec: Close halts the camera immediately, then confirms. Cancel reloads the page (a
	 * one-click unfreeze for a locked-up mobile webcam stream); Continue routes the student
	 * away from the lesson. This is never a verification bypass — it does not grant access to
	 * protected content, only lets a stuck user leave gracefully instead of being trapped.
	 */
	function onCloseButtonClick() {
		if (activeStream) {
			stopStream(activeStream);
			activeStream = null;
		}

		if (window.confirm(config.i18n.closeConfirm)) {
			intentionalHide = true;
			window.location.href = config.closeButtonRedirectUrl || '/';
		} else {
			window.location.reload();
		}
	}

	function showOverlay() {
		if (!overlayEl.open) {
			overlayEl.showModal();
		}
		setStatus('');
		startBtn.disabled = false;
		startBtn.hidden = false;

		var bypassBtn = overlayEl.querySelector('.bg-gate-dev-bypass-btn');
		if (bypassBtn) {
			bypassBtn.disabled = false;
		}

		if (!isBlockingShell) {
			document.documentElement.classList.add('bg-gate-blur-active');

			pausedMedia = [];
			pauseAllMedia();
			if (mediaRescanTimer) {
				window.clearInterval(mediaRescanTimer);
			}
			// A one-time sweep missed players that mount/reinitialize *after* the overlay
			// opened (e.g. an autoplay-next-lesson video) — this keeps re-checking for as
			// long as the lockout is showing, which is what "audio keeps playing in the
			// background" during a scan traced back to.
			mediaRescanTimer = window.setInterval(pauseAllMedia, MEDIA_RESCAN_INTERVAL_MS);
		}
	}

	function hideOverlay() {
		intentionalHide = true;
		if (overlayEl.open) {
			overlayEl.close();
		}
		document.documentElement.classList.remove('bg-gate-blur-active');

		if (mediaRescanTimer) {
			window.clearInterval(mediaRescanTimer);
			mediaRescanTimer = null;
		}

		resumeAllMedia();

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
			if (!killSwitchEnabled('domTamper') || intentionalHide || !overlayEl) {
				return;
			}
			var stillPresent = document.body.contains(overlayEl);
			var stillVisible = stillPresent && overlayEl.open && 'none' !== window.getComputedStyle(overlayEl).display;

			if (overlayEl.open && (!stillPresent || !stillVisible)) {
				killSwitch('overlay_tampered');
			}
		});

		observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'open'] });
	}

	/**
	 * Best-effort, heuristic DevTools-open detector: a docked devtools panel shrinks the
	 * viewport relative to the outer window. This is imperfect by nature (undocked/separate-
	 * window devtools, or a resized browser window on its own, can both evade or false-
	 * positive it) — defaults OFF in Tab B for exactly that reason.
	 */
	function startDevtoolsWatch() {
		if (!killSwitchEnabled('devtools') || devtoolsCheckTimer) {
			return;
		}

		devtoolsCheckTimer = window.setInterval(function () {
			var widthDelta = window.outerWidth - window.innerWidth;
			var heightDelta = window.outerHeight - window.innerHeight;

			if (widthDelta > DEVTOOLS_SIZE_THRESHOLD || heightDelta > DEVTOOLS_SIZE_THRESHOLD) {
				window.clearInterval(devtoolsCheckTimer);
				devtoolsCheckTimer = null;
				killSwitch('devtools_detected');
			}
		}, 1000);
	}

	/**
	 * Deterrent only — right-click/F12/common devtools shortcuts and an admin-defined custom
	 * key list get preventDefault()'d. This is friction against casual snooping, not a real
	 * security boundary (the deny-by-default server-side content guard is); browser-chrome-
	 * level shortcuts some OS/browser combinations reserve before JS ever sees them (e.g.
	 * Cmd+Option+I in Safari) can't be intercepted from a webpage at all.
	 */
	function setupInputBlocking() {
		if (!config.blockDevtoolsShortcuts) {
			return;
		}

		document.addEventListener('contextmenu', function (e) {
			e.preventDefault();
		});

		var blockedCombos = [
			{ key: 'F12' },
			{ key: 'I', ctrl: true, shift: true },
			{ key: 'J', ctrl: true, shift: true },
			{ key: 'C', ctrl: true, shift: true },
			{ key: 'U', ctrl: true },
		];
		var customKeys = (config.blockedKeysCustom || []).map(function (k) {
			return String(k).toUpperCase();
		});

		document.addEventListener('keydown', function (e) {
			var key = (e.key || '').toUpperCase();

			var comboMatch = blockedCombos.some(function (combo) {
				if (combo.key.toUpperCase() !== key) {
					return false;
				}
				if (combo.ctrl && !(e.ctrlKey || e.metaKey)) {
					return false;
				}
				if (combo.shift && !e.shiftKey) {
					return false;
				}
				return true;
			});

			if (comboMatch || customKeys.indexOf(key) !== -1) {
				e.preventDefault();
			}
		});
	}

	/**
	 * Recovers a scan that was mid-flight when the tab/app was backgrounded: on iOS
	 * specifically, a camera stream commonly dies while the page is hidden, and drawing from
	 * a dead/stale video element produced both a visual freeze and a false "Environment Too
	 * Dark" (a black stale frame reads as near-zero luminance). Dropping the dead stream and
	 * resetting to the Start button lets the student cleanly re-trigger instead of being stuck.
	 */
	function onVisibilityChange() {
		if (document.hidden || !activeStream) {
			return;
		}

		var stillLive = activeStream.getVideoTracks().every(function (t) {
			return 'live' === t.readyState;
		});

		if (!stillLive) {
			stopStream(activeStream);
			activeStream = null;

			if (overlayEl && overlayEl.open) {
				setStatus('');
				startBtn.disabled = false;
				startBtn.hidden = false;
			}
		}
	}

	/**
	 * Continuously (not just once) targets only the generic <video>/<audio>/<iframe> web
	 * standards — no PrestoPlayer-, YouTube-, or Vimeo-specific selectors or classes, per the
	 * spec's "no LearnDash/PrestoPlayer-specific code" portability rule. Called repeatedly via
	 * mediaRescanTimer while the overlay is open (see showOverlay), not just once at open time,
	 * so a player that mounts or resumes mid-lockout still gets caught and paused.
	 */
	function pauseAllMedia() {
		// Include Presto Player web components in the media element query
		var mediaElements = document.querySelectorAll('video, audio, presto-player, presto-youtube, presto-vimeo, presto-video');
		mediaElements.forEach(function (el) {
			if (el === videoEl) {
				return;
			}
			try {
				var isPlaying = false;
				if (el.tagName && el.tagName.toLowerCase().indexOf('presto') !== -1) {
					// We always want to resume presto players since we can't reliably read their state synchronously
					isPlaying = true;
				} else {
					isPlaying = (el.currentTime > 0 && !el.paused && !el.ended && el.readyState > 2);
				}

				if (isPlaying && -1 === pausedMedia.indexOf(el)) {
					pausedMedia.push(el);
				}
				if (typeof el.pause === 'function') {
					el.pause();
				}
			} catch (e) { /* noop */ }
		});
		document.querySelectorAll('iframe').forEach(function (frame) {
			try {
				if (-1 === pausedMedia.indexOf(frame)) {
					pausedMedia.push(frame);
				}
				frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }), '*');
				frame.contentWindow.postMessage(JSON.stringify({ method: 'pause' }), '*');
				frame.contentWindow.postMessage('{"method":"pause"}', '*');
				frame.contentWindow.postMessage('pause', '*');
			} catch (e) { /* skip */ }
		});
	}

	function resumeAllMedia() {
		pausedMedia.forEach(function (el) {
			try {
				if (typeof el.play === 'function') {
					el.play();
				}
				if (el.tagName && el.tagName.toLowerCase() === 'iframe') {
					el.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*');
					el.contentWindow.postMessage(JSON.stringify({ method: 'play' }), '*');
					el.contentWindow.postMessage('{"method":"play"}', '*');
					el.contentWindow.postMessage('play', '*');
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

		// CRITICAL FIX: If a stream exists but the video element is not playing or 
		// the stream is dead, stop it and request a fresh one.
		if (activeStream) {
			var isStreamLive = activeStream.getVideoTracks().every(function (t) {
				return 'live' === t.readyState;
			});

			// If the stream is dead or the video element is not rendering, kill it.
			if (!isStreamLive || videoEl.readyState < 2 || videoEl.videoWidth === 0) {
				stopStream(activeStream);
				activeStream = null;
			}
		}

		if (activeStream) {
			captureAndSubmit(activeStream, false);
		} else {
			setStatus(config.i18n.verifying || "Starting camera...");
			getValidCameraStream()
				.then(auditDevicesThenCapture)
				.catch(handleCameraError);
		}
	}

	function getValidCameraStream() {
		// 1. Get initial generic stream to prompt permissions and unmask device labels in the browser.
		return navigator.mediaDevices.getUserMedia({ video: true })
			.then(function (initialStream) {
				// 2. Enumerate all devices now that labels are fully visible.
				return navigator.mediaDevices.enumerateDevices().then(function (devices) {
					var videoDevices = devices.filter(function (d) { return d.kind === 'videoinput'; });
					var validDevice = null;

					for (var i = 0; i < videoDevices.length; i++) {
						var label = (videoDevices[i].label || '').trim().toLowerCase();
						if (label && !VIRTUAL_CAMERA_PATTERN.test(label)) {
							validDevice = videoDevices[i];
							break;
						}
					}

					// 3. Stop the initial generic stream so we can request the specific one with ideal constraints.
					stopStream(initialStream);

					var constraints = buildVideoConstraints();

					// 4. Force target the first physical hardware camera found.
					if (validDevice) {
						constraints.deviceId = { exact: validDevice.deviceId };
						try {
							window.localStorage.setItem('bg_preferred_camera_id', validDevice.deviceId);
						} catch (e) { /* noop */ }
					}

					return navigator.mediaDevices.getUserMedia({ video: constraints });
				});
			});
	}

	function buildVideoConstraints() {
		var preferred = null;
		try {
			preferred = window.localStorage.getItem('bg_preferred_camera_id');
		} catch (e) { /* localStorage unavailable — fall back to default camera. */ }

		// ideal (not exact/min) resolution: the browser targets this but gracefully degrades
		// on hardware that can't provide it, rather than failing outright. A higher-quality
		// source stream is what actually improves FaceIO's match confidence — enlarging just
		// the output canvas without this would only upscale a low-res source.
		var constraints = {
			width: { ideal: 1280 },
			height: { ideal: 960 },
		};

		if (preferred) {
			constraints.deviceId = { exact: preferred };
		}

		return constraints;
	}

	/**
	 * Device-label auditing runs *after* getUserMedia grants permission and inspects only the
	 * device actually backing this live stream (via the track's own label) — not every
	 * camera-like device enumerable on the system. Auditing the whole device list previously
	 * flagged any Mac with something like OBS merely *installed* (even idle, unused) and
	 * killed the session before the video ever appeared, which is what desktop Mac/Chrome and
	 * Safari testing was consistently hitting.
	 */
	function auditDevicesThenCapture(stream) {
		if (killSwitchEnabled('virtualCamera')) {
			var videoTrack = stream.getVideoTracks()[0];
			var activeLabel = ((videoTrack && videoTrack.label) || '').trim().toLowerCase();

			if ('' === activeLabel || VIRTUAL_CAMERA_PATTERN.test(activeLabel)) {
				stopStream(stream);
				killSwitch('virtual_camera_detected');
				return Promise.resolve();
			}
		}

		activeStream = stream;
		watchStreamLifecycle(stream);
		return captureAndSubmit(stream, true);
	}

	function watchStreamLifecycle(stream) {
		stream.getVideoTracks().forEach(function (track) {
			track.onended = function () {
				if (activeStream === stream) {
					activeStream = null;
				}
			};
		});
	}

	/**
	 * Grid-sampled (every 10th pixel, not every pixel) average perceptual luminance of the
	 * already-drawn canvas frame — cheap enough to run on every capture attempt.
	 */
	function measureAverageLuminance(ctx) {
		// Sample only the center 50% of the frame to prevent dark backgrounds 
		// or letterboxing black bars from skewing the average, guaranteeing we measure the face!
		var w = CAPTURE_WIDTH * 0.5;
		var h = CAPTURE_HEIGHT * 0.5;
		var x = CAPTURE_WIDTH * 0.25;
		var y = CAPTURE_HEIGHT * 0.25;
		var data = ctx.getImageData(x, y, w, h).data;
		var total = 0;
		var count = 0;

		for (var i = 0; i < data.length; i += 40) { // 4 bytes/pixel * 10 pixels.
			total += (0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]);
			count++;
		}

		return count > 0 ? (total / count) : 255;
	}

	/**
	 * Draws the live video into the capture canvas, center-cropped to the oval guide's
	 * portrait aspect ratio (so background noise outside the guide never reaches FaceIO) and
	 * with a light contrast/brightness normalization applied — the honest, implementable
	 * stand-in for "face normalization" described in the class docblock above.
	 */
	function drawNormalizedFrame(ctx) {
		var videoW = videoEl.videoWidth || CAPTURE_WIDTH;
		var videoH = videoEl.videoHeight || CAPTURE_HEIGHT;
		var targetAspect = CAPTURE_WIDTH / CAPTURE_HEIGHT;

		var srcW = Math.min(videoW, videoH * targetAspect);
		var srcH = Math.min(videoH, videoW / targetAspect);
		var srcX = (videoW - srcW) / 2;
		var srcY = (videoH - srcH) / 2;

		ctx.clearRect(0, 0, CAPTURE_WIDTH, CAPTURE_HEIGHT);

		// CRITICAL FIREFOX FIX: NEVER use ctx.filter = '...' here!
		// Firefox has a known engine bug where applying filters while drawing 
		// a live WebRTC video feed turns the entire canvas completely pitch black!
		ctx.drawImage(videoEl, srcX, srcY, srcW, srcH, 0, 0, CAPTURE_WIDTH, CAPTURE_HEIGHT);
	}

	function captureAndSubmit(stream, isFirstTime) {
		if (isFirstTime) {
			// CRITICAL FIX: Explicitly clear and reset the video element 
			// to prevent stale frames from the previous stream.
			videoEl.pause();
			videoEl.srcObject = null;
			videoEl.load(); // Forces the browser to release the old stream

			videoEl.srcObject = stream;

			// Ensure the video is muted and plays inline (required for autoplay policies)
			videoEl.muted = true;
			videoEl.playsInline = true;
		}

		return new Promise(function (resolve) {
			var takePicture = function () {
				setStatus(config.i18n.verifying || "Verifying...");

				var ctx = canvasEl.getContext('2d', { willReadFrequently: true });
				drawNormalizedFrame(ctx);

				var luminance = measureAverageLuminance(ctx);

				if (luminance < DARKNESS_THRESHOLD) {
					// Never reaches FaceIO, so this doesn't count as one of the 3 strikes —
					// the user just needs better lighting and can hit Start Face Scan again.
					setStatus(config.i18n.tooDark || 'Environment Too Dark. Please turn on a light to continue.');
					startBtn.disabled = false;
					startBtn.hidden = false;
					return;
				}

				if (luminance > BRIGHTNESS_THRESHOLD) {
					setStatus(config.i18n.tooBright || 'Too much light/glare detected.');
					startBtn.disabled = false;
					startBtn.hidden = false;
					return;
				}

				var dataUrl = canvasEl.toDataURL('image/jpeg', 0.9);
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

			var startCountdown = function () {
				// Double check that the video is actually rendering frames
				if (videoEl.readyState < 2 || videoEl.videoWidth === 0) {
					setTimeout(startCountdown, 100); // Wait 100ms and try again
					return;
				}

				var countdown = 3;
				var guidance = [config.i18n.centerFace, config.i18n.moveCloser];
				var guidanceIndex = 0;

				setStatus((guidance[guidanceIndex] || '') + ' (' + countdown + ')');

				var interval = setInterval(function () {
					countdown--;
					guidanceIndex = (guidanceIndex + 1) % guidance.length;

					if (countdown > 0) {
						setStatus((guidance[guidanceIndex] || '') + ' (' + countdown + ')');
					} else {
						clearInterval(interval);
						takePicture();
					}
				}, 1000);
			};

			if (isFirstTime) {
				videoEl.onloadedmetadata = function () {
					// Explicitly play the video to kickstart the WebRTC pipeline
					var playPromise = videoEl.play();
					if (playPromise !== undefined) {
						playPromise.then(startCountdown).catch(function (err) {
							console.error("Video play failed:", err);
							// Fallback: try to start countdown anyway if play() is blocked
							startCountdown();
						});
					} else {
						startCountdown();
					}
				};
			} else {
				startCountdown();
			}
		});
	}

	function handleCameraError(err) {
		// As requested: if a student's webcam is missing or disabled in their browser, 
		// automatically stop the scan from firing and neatly display the custom text block 
		// instead of giving them a failure strike.
		setStatus(config.noCameraMessage || config.i18n.noCamera, true);
		startBtn.disabled = false;

		if (activeStream) {
			stopStream(activeStream);
			activeStream = null;
		}
	}

	function stopStream(stream) {
		if (stream && stream.getTracks) {
			stream.getTracks().forEach(function (track) { track.stop(); });
		}
	}

	function handleScanSuccess(res) {
		if (res && res.status === 'redirected') {
			window.location.href = res.redirect_url || '/';
			return;
		}

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

		var videoFrame = overlayEl.querySelector('.bg-gate-video-frame');
		if (videoFrame) videoFrame.style.display = 'none';

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

		var videoFrame = overlayEl.querySelector('.bg-gate-video-frame');
		if (videoFrame) videoFrame.style.display = '';

		runScanCycle();
	}

	/**
	 * The admin-configured action for whichever violation fired (Tab B: the 3-strike
	 * "Biometric Fail Routing" box for max_retries_exceeded, or the per-type kill-switch grid
	 * for everything else) is resolved server-side and returned here — this never guesses or
	 * duplicates that logic client-side, so there is exactly one source of truth.
	 */
	function killSwitch(reason) {
		if (activeStream) {
			stopStream(activeStream);
			activeStream = null;
		}

		var navigated = false;
		var navigateAway = function (redirectUrl) {
			if (navigated) {
				return;
			}
			navigated = true;
			window.location.href = redirectUrl || '/';
		};

		apiPost('/session/killswitch', { reason: reason, page_url: config.pageUrl })
			.then(function (res) {
				navigateAway(res && res.redirect_url);
			})
			.catch(function () {
				navigateAway(null);
			});

		window.setTimeout(function () {
			navigateAway(null);
		}, 3000);
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
