/**
 * Biometric Gate — frontend lockout overlay, gesture-anchored camera capture, rolling
 * re-verification timer, and kill-switch. Vanilla JS, no build step, no framework/media-
 * player-brand dependency — targets only generic <video>/<iframe> DOM and the Fetch/
 * MediaDevices web standards, so it's portable to any WordPress theme/plugin stack (spec #11).
 *
 * Trust model: this script never decides "verified" on its own. It captures a frame and
 * hands it to the server; every decision that matters (match/no-match, session validity,
 * rolling-window bypass) comes back from the REST API and is treated as authoritative.
 */
( function () {
	'use strict';

	if ( typeof window.BiometricGateConfig === 'undefined' ) {
		return;
	}

	var config = window.BiometricGateConfig;
	var root = document.getElementById( 'bg-gate-root' );
	var isBlockingShell = !! root && root.dataset.bgMode === 'verify';

	// A non-'verify' shell (not-enrolled / locked) is a static server-rendered message —
	// nothing for this script to drive.
	if ( root && root.dataset.bgMode && 'verify' !== root.dataset.bgMode ) {
		return;
	}

	var overlayEl, videoEl, canvasEl, statusEl, startBtn, cameraSelectEl;
	var currentTicket = null;
	var retryCount = 0;
	var intentionalHide = false;
	var rescheduleTimer = null;
	var reconnectPollTimer = null;

	var MAX_RETRIES = 3;
	var VIRTUAL_CAMERA_PATTERN = /virtual|obs|splitcam|software engine|manycam|droidcam/i;
	var CAPTURE_WIDTH = 320;
	var CAPTURE_HEIGHT = 240;

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		buildOverlayScaffold();
		observeTampering();
		runScanCycle();
	}

	// ---------------------------------------------------------------------
	// Overlay DOM
	// ---------------------------------------------------------------------

	function buildOverlayScaffold() {
		overlayEl = document.createElement( 'div' );
		overlayEl.id = 'bg-gate-overlay';
		overlayEl.setAttribute( 'role', 'dialog' );
		overlayEl.setAttribute( 'aria-modal', 'true' );
		overlayEl.hidden = true;

		overlayEl.innerHTML =
			'<div class="bg-gate-card">' +
				'<h2></h2>' +
				'<p class="bg-gate-status" aria-live="polite"></p>' +
				'<div class="bg-gate-video-frame"><video playsinline autoplay muted></video></div>' +
				'<select class="bg-gate-camera-select" hidden></select>' +
				'<button type="button" class="bg-gate-start-btn"></button>' +
			'</div>';

		document.body.appendChild( overlayEl );

		videoEl = overlayEl.querySelector( 'video' );
		statusEl = overlayEl.querySelector( '.bg-gate-status' );
		startBtn = overlayEl.querySelector( '.bg-gate-start-btn' );
		cameraSelectEl = overlayEl.querySelector( '.bg-gate-camera-select' );

		canvasEl = document.createElement( 'canvas' );
		canvasEl.width = CAPTURE_WIDTH;
		canvasEl.height = CAPTURE_HEIGHT;

		overlayEl.querySelector( 'h2' ).textContent = config.i18n.startScan;
		startBtn.textContent = config.i18n.startScan;
		startBtn.addEventListener( 'click', onStartScanClick );
	}

	function showOverlay() {
		overlayEl.hidden = false;
		setStatus( '' );
		startBtn.disabled = false;
		startBtn.hidden = false;

		if ( ! isBlockingShell ) {
			document.documentElement.classList.add( 'bg-gate-blur-active' );
			pauseAllMedia();
		}
	}

	function hideOverlay() {
		intentionalHide = true;
		overlayEl.hidden = true;
		document.documentElement.classList.remove( 'bg-gate-blur-active' );
		window.setTimeout( function () {
			intentionalHide = false;
		}, 0 );
	}

	function setStatus( text ) {
		statusEl.textContent = text;
	}

	/**
	 * Spec #7: if a student deletes/hides the overlay element via DevTools while a scan is
	 * still pending (i.e. not via our own hideOverlay()), treat it exactly like a manual
	 * modal-close attempt and kill the session.
	 */
	function observeTampering() {
		var observer = new MutationObserver( function () {
			if ( intentionalHide || ! overlayEl ) {
				return;
			}
			var stillPresent = document.body.contains( overlayEl );
			var stillVisible = stillPresent && 'none' !== window.getComputedStyle( overlayEl ).display && ! overlayEl.hidden;

			if ( ! overlayEl.hidden && ( ! stillPresent || ! stillVisible ) ) {
				killSwitch( 'overlay_tampered' );
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style', 'hidden', 'class' ] } );
	}

	// ---------------------------------------------------------------------
	// Universal media pausing (spec #4) — generic <video> + postMessage() to iframes only,
	// no PrestoPlayer/YouTube/Vimeo-specific selectors.
	// ---------------------------------------------------------------------

	function pauseAllMedia() {
		document.querySelectorAll( 'video' ).forEach( function ( v ) {
			try {
				v.pause();
			} catch ( e ) { /* noop */ }
		} );

		document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
			try {
				// YouTube IFrame API wire format.
				frame.contentWindow.postMessage( JSON.stringify( { event: 'command', func: 'pauseVideo', args: [] } ), '*' );
				// Vimeo Player API wire format.
				frame.contentWindow.postMessage( JSON.stringify( { method: 'pause' } ), '*' );
			} catch ( e ) { /* Cross-origin frames that reject postMessage shape are simply skipped. */ }
		} );
	}

	// ---------------------------------------------------------------------
	// Scan lifecycle
	// ---------------------------------------------------------------------

	function runScanCycle() {
		apiPost( '/scan/start', { page_title: config.pageTitle, page_url: config.pageUrl } )
			.then( function ( res ) {
				if ( res.bypass ) {
					if ( isBlockingShell ) {
						// Narrow race: guard window became valid after the server rendered the
						// content-free shell. Reload once to fetch the now-permitted real content.
						window.location.reload();
						return;
					}
					hideOverlay();
					scheduleNextCheck( res.seconds_until_rescan || config.scanThresholdSec );
					return;
				}

				currentTicket = res.ticket;
				retryCount = 0;
				showOverlay();
			} )
			.catch( function () {
				showConnectionLost();
			} );
	}

	function onStartScanClick() {
		startBtn.disabled = true;
		setStatus( config.i18n.verifying );

		navigator.mediaDevices.getUserMedia( { video: buildVideoConstraints() } )
			.then( auditDevicesThenCapture )
			.catch( handleCameraError );
	}

	function buildVideoConstraints() {
		var preferred = null;
		try {
			preferred = window.localStorage.getItem( 'bg_preferred_camera_id' );
		} catch ( e ) { /* localStorage unavailable — fall back to default camera. */ }

		return preferred ? { deviceId: { exact: preferred } } : true;
	}

	/**
	 * Device-label auditing runs *after* getUserMedia grants permission, not before: browsers
	 * withhold device labels entirely pre-permission (privacy), so every legitimate first-time
	 * visitor would show empty labels and false-positive a kill-switch if audited first.
	 */
	function auditDevicesThenCapture( stream ) {
		return navigator.mediaDevices.enumerateDevices().then( function ( devices ) {
			var videoInputs = devices.filter( function ( d ) {
				return 'videoinput' === d.kind;
			} );

			maybePopulateCameraSelect( videoInputs );

			var suspicious = videoInputs.some( function ( d ) {
				var label = ( d.label || '' ).trim().toLowerCase();
				return '' === label || VIRTUAL_CAMERA_PATTERN.test( label );
			} );

			if ( suspicious ) {
				stopStream( stream );
				killSwitch( 'virtual_camera_detected' );
				return;
			}

			return captureAndSubmit( stream );
		} );
	}

	/**
	 * Spec #8: multi-camera support so a user who has dragged the browser to an external
	 * monitor can pick that display's webcam instead of the laptop's built-in one.
	 */
	function maybePopulateCameraSelect( videoInputs ) {
		if ( videoInputs.length < 2 ) {
			cameraSelectEl.hidden = true;
			return;
		}

		cameraSelectEl.innerHTML = '';
		videoInputs.forEach( function ( d, i ) {
			var opt = document.createElement( 'option' );
			opt.value = d.deviceId;
			opt.textContent = d.label || 'Camera ' + ( i + 1 );
			cameraSelectEl.appendChild( opt );
		} );

		try {
			var preferred = window.localStorage.getItem( 'bg_preferred_camera_id' );
			if ( preferred ) {
				cameraSelectEl.value = preferred;
			}
		} catch ( e ) { /* noop */ }

		cameraSelectEl.hidden = false;
		cameraSelectEl.onchange = function () {
			try {
				window.localStorage.setItem( 'bg_preferred_camera_id', cameraSelectEl.value );
			} catch ( e ) { /* noop */ }
		};
	}

	function captureAndSubmit( stream ) {
		videoEl.srcObject = stream;

		return new Promise( function ( resolve ) {
			videoEl.onloadeddata = function () {
				var ctx = canvasEl.getContext( '2d' );
				ctx.drawImage( videoEl, 0, 0, CAPTURE_WIDTH, CAPTURE_HEIGHT );
				var dataUrl = canvasEl.toDataURL( 'image/jpeg', 0.85 );
				var base64 = dataUrl.split( ',' )[ 1 ] || '';

				stopStream( stream );

				resolve(
					apiPost( '/scan/result', {
						ticket: currentTicket,
						frame: base64,
						page_title: config.pageTitle,
						page_url: config.pageUrl,
					} )
						.then( handleScanSuccess )
						.catch( handleScanError )
				);
			};
		} );
	}

	function handleScanSuccess( res ) {
		retryCount = 0;
		hideOverlay();

		if ( isBlockingShell ) {
			window.location.reload();
			return;
		}

		scheduleNextCheck( res.seconds_until_rescan || config.scanThresholdSec );
	}

	function handleScanError( err ) {
		if ( err && 'bg_invalid_ticket' === err.code ) {
			// Ticket expired/raced — silently fetch a fresh one, no strike counted.
			runScanCycle();
			return;
		}

		retryCount += 1;

		if ( retryCount >= MAX_RETRIES ) {
			killSwitch( 'max_retries_exceeded' );
			return;
		}

		setStatus( config.i18n.scanFailed + ' (' + retryCount + '/' + MAX_RETRIES + ')' );
		startBtn.disabled = false;

		// The spent ticket can't be reused — mint a fresh one for the next attempt, but keep
		// the overlay open (no need to re-run the guard-window bypass check mid-lockout).
		apiPost( '/scan/start', { page_title: config.pageTitle, page_url: config.pageUrl } ).then( function ( res ) {
			if ( ! res.bypass ) {
				currentTicket = res.ticket;
			}
		} );
	}

	function handleCameraError( err ) {
		var name = err && err.name ? err.name : '';

		if ( 'NotFoundError' === name || 'OverconstrainedError' === name ) {
			showNoCameraBlock();
			return;
		}

		// Permission denied, or any other getUserMedia failure — counts as a failed attempt,
		// same as a failed face match, so a student can't dodge verification by repeatedly
		// declining the camera prompt.
		handleScanError( { code: 'bg_camera_denied' } );
	}

	function showNoCameraBlock() {
		overlayEl.querySelector( '.bg-gate-card' ).innerHTML =
			'<div class="bg-gate-block-message">' + config.noCameraMessage + '</div>';
	}

	function stopStream( stream ) {
		if ( stream && stream.getTracks ) {
			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
		}
	}

	// ---------------------------------------------------------------------
	// Rolling interval loop (Trigger B) — server-issued duration only; localStorage is a
	// display-only mirror, never the source of truth (spec #3).
	// ---------------------------------------------------------------------

	function scheduleNextCheck( seconds ) {
		if ( rescheduleTimer ) {
			window.clearTimeout( rescheduleTimer );
		}

		try {
			window.localStorage.setItem( 'bg_expires_at', String( Date.now() + seconds * 1000 ) );
		} catch ( e ) { /* Purely cosmetic — safe to skip if storage is unavailable. */ }

		rescheduleTimer = window.setTimeout( runScanCycle, Math.max( 1000, seconds * 1000 ) );
	}

	// ---------------------------------------------------------------------
	// Network-loss handling (spec #5): a local connectivity drop freezes the lockout and
	// never grants a bypass; only a *FACEIO-side* timeout (handled entirely server-side as a
	// 200 "cloud_bypass" response) grants the one-loop grace period.
	// ---------------------------------------------------------------------

	function showConnectionLost() {
		showOverlay();
		startBtn.hidden = true;
		setStatus( config.i18n.connectionLost );

		if ( ! reconnectPollTimer ) {
			window.addEventListener( 'online', onConnectionRestored, { once: true } );
			reconnectPollTimer = window.setInterval( function () {
				if ( navigator.onLine ) {
					onConnectionRestored();
				}
			}, 5000 );
		}
	}

	function onConnectionRestored() {
		if ( reconnectPollTimer ) {
			window.clearInterval( reconnectPollTimer );
			reconnectPollTimer = null;
		}
		startBtn.hidden = false;
		runScanCycle();
	}

	// ---------------------------------------------------------------------
	// Kill-switch (spec #7)
	// ---------------------------------------------------------------------

	function killSwitch( reason ) {
		var redirected = false;
		var goHome = function () {
			if ( redirected ) {
				return;
			}
			redirected = true;
			window.location.href = '/';
		};

		apiPost( '/session/killswitch', { reason: reason } ).then( goHome ).catch( goHome );
		window.setTimeout( goHome, 3000 ); // Safety net if the request hangs.
	}

	// ---------------------------------------------------------------------
	// REST helper
	// ---------------------------------------------------------------------

	function apiPost( path, body ) {
		return fetch( config.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! response.ok ) {
					var err = new Error( json.message || 'Request failed' );
					err.code = json.code;
					err.status = response.status;
					throw err;
				}
				return json;
			} );
		} );
	}
} )();
