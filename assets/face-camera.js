/**
 * assets/face-camera.js
 *
 * Shared camera bootstrap used by every face-capture screen
 * (public registration + faculty verification).
 *
 * Fixes covered here (see FIXES.md "Task 1 / Task 2"):
 *  - Uses navigator.mediaDevices.getUserMedia() directly (with a graded
 *    fallback list of constraints) instead of the MediaPipe `Camera` helper,
 *    whose own internal getUserMedia call is the source of the
 *    "Failed to acquire camera feed: NotAllowedError: Permission Denied"
 *    message some phones were showing, and which has no retry/fallback path.
 *  - Waits for the `loadedmetadata` event (and a real videoWidth/videoHeight)
 *    before starting detection, which is what was causing the black/blank
 *    preview with "Camera Ready" text on some Android/Samsung Internet
 *    builds that fire `canplay` before the stream frame is actually decodable.
 *  - Sets playsinline / webkit-playsinline via both the HTML attribute and
 *    the DOM property (iOS Safari only reliably honors the latter when set
 *    before `play()` is called).
 *  - Distinguishes NotAllowedError / NotFoundError / NotReadableError /
 *    OverconstrainedError / SecurityError so the on-screen message tells the
 *    person what to actually do, instead of a generic failure.
 *  - Retries once automatically with relaxed constraints on
 *    OverconstrainedError (some older Android front cameras reject an
 *    `ideal` resolution hint).
 *  - Runs its own throttled requestAnimationFrame loop instead of relying on
 *    a library-owned timer, so it can be paused/stopped deterministically
 *    (prevents duplicate/leaked frame callbacks if a page re-initializes).
 */
(function (global) {
  'use strict';

  const CONSTRAINT_FALLBACKS = [
    { video: { facingMode: { ideal: 'user' }, width: { ideal: 640 }, height: { ideal: 480 } }, audio: false },
    { video: { facingMode: 'user' }, audio: false },
    { video: true, audio: false },
  ];

  /**
   * Explicit iOS / Safari detection (requested for camera-compatibility
   * handling). iOS locks all browsers to WebKit under the hood, so "iOS"
   * and "Safari-engine" are effectively the same constraint set here.
   */
  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS 13+ reports as Mac
  }
  function isSafari() {
    const ua = navigator.userAgent;
    return /^((?!chrome|android|crios|fxios|edg).)*safari/i.test(ua);
  }

  function describeCameraError(err) {
    const name = (err && err.name) || '';
    switch (name) {
      case 'NotAllowedError':
      case 'PermissionDeniedError':
        return isIOS()
          ? 'Please allow camera access in your browser settings. On iPhone/iPad: Settings → Safari → Camera (or tap the "aA" icon in the address bar → Website Settings), then reload the page.'
          : 'Please allow camera access in your browser settings.';
      case 'NotFoundError':
      case 'DevicesNotFoundError':
        return 'No camera could be found on this device.';
      case 'NotReadableError':
      case 'TrackStartError':
        return 'The camera appears to be in use by another app. Close other apps or browser tabs using the camera and try again.';
      case 'OverconstrainedError':
      case 'ConstraintNotSatisfiedError':
        return 'This camera does not support the requested settings. Retrying with basic settings…';
      case 'SecurityError':
        return 'Camera access requires a secure (HTTPS) connection.';
      case 'AbortError':
        return 'Camera initialization was interrupted. Please try again.';
      default:
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          return 'This browser does not support camera access. Please use a recent version of Chrome, Edge, Firefox, or Safari.';
        }
        return 'Unable to access the camera. Please refresh the page.';
    }
  }

  /**
   * Estimate average luma (0-255) of the current video frame to catch
   * "too dark to be useful" captures before they are sent to face-api.js.
   */
  function estimateBrightness(video) {
    try {
      const w = 32, h = 24;
      const cvs = estimateBrightness._cvs || (estimateBrightness._cvs = document.createElement('canvas'));
      cvs.width = w; cvs.height = h;
      const ctx = cvs.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(video, 0, 0, w, h);
      const data = ctx.getImageData(0, 0, w, h).data;
      let sum = 0;
      for (let i = 0; i < data.length; i += 4) {
        sum += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      }
      return sum / (data.length / 4);
    } catch (e) {
      return 128; // canvas read failed (e.g. tainted) — don't block capture on this alone
    }
  }

  /**
   * Start the camera on <video>, resolving once a real frame is ready.
   * opts:
   *   - onFrame(): called on a throttled loop (default ~8fps) once the
   *     stream has real dimensions.
   *   - fps: target frame callback rate (default 8).
   *   - onRetryNotice(msg): optional, called if a constraint fallback kicks in.
   */
  async function start(video, opts) {
    opts = opts || {};
    const fps = opts.fps || 8;
    const frameInterval = 1000 / fps;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      const e = new Error('getUserMedia unsupported');
      e.name = 'NotSupportedError';
      throw e;
    }

    // iOS Safari + some in-app browsers only respect these when set as real
    // attributes (not just DOM properties) *before* srcObject/play().
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    video.autoplay = true;

    let stream = null;
    let lastErr = null;
    for (let i = 0; i < CONSTRAINT_FALLBACKS.length; i++) {
      try {
        stream = await navigator.mediaDevices.getUserMedia(CONSTRAINT_FALLBACKS[i]);
        if (i > 0 && typeof opts.onRetryNotice === 'function') {
          opts.onRetryNotice(describeCameraError(lastErr));
        }
        break;
      } catch (e) {
        lastErr = e;
        // Permission/hardware errors won't be fixed by relaxing constraints —
        // stop retrying immediately and surface the real reason.
        if (e && (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError' ||
                  e.name === 'NotFoundError' || e.name === 'NotReadableError' ||
                  e.name === 'SecurityError')) {
          break;
        }
        // Otherwise (e.g. OverconstrainedError) fall through and try the next,
        // looser constraint set.
      }
    }

    if (!stream) {
      const e = lastErr || new Error('Camera unavailable');
      e.friendlyMessage = describeCameraError(lastErr);
      throw e;
    }

    video.srcObject = stream;

    // Wait for real, decodable metadata (fixes the "Camera Ready" black
    // preview: some browsers fire earlier events before width/height exist).
    await new Promise((resolve, reject) => {
      let settled = false;
      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        stream.getTracks().forEach(t => t.stop());
        const e = new Error('Camera timed out while starting.');
        e.friendlyMessage = 'Unable to access the camera. Please refresh the page.';
        reject(e);
      }, 15000);

      const onReady = () => {
        if (settled) return;
        if (video.videoWidth > 0 && video.videoHeight > 0) {
          settled = true;
          clearTimeout(timer);
          resolve();
        }
      };
      video.onloadedmetadata = onReady;
      video.onloadeddata = onReady;
      video.oncanplay = onReady;
      // Some browsers already have metadata by the time we attach listeners.
      onReady();
    });

    try {
      await video.play();
    } catch (e) {
      // Autoplay can be blocked without a user gesture on some browsers even
      // though getUserMedia already succeeded; the click that triggered this
      // page load usually counts as the gesture, but if it doesn't, surface
      // a clear, actionable message instead of a silently frozen video.
      const err = new Error('Autoplay blocked');
      err.friendlyMessage = 'Tap/click anywhere on the page once to start the camera preview.';
      throw err;
    }

    let active = true;
    let lastTick = 0;
    function loop(ts) {
      if (!active) return;
      if (ts - lastTick >= frameInterval) {
        lastTick = ts;
        if (video.readyState >= 2 && typeof opts.onFrame === 'function') {
          opts.onFrame();
        }
      }
      requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);

    return {
      stream,
      stop() {
        active = false;
        stream.getTracks().forEach(t => { try { t.stop(); } catch (e) {} });
      },
    };
  }

  global.FaceCamera = { start, describeCameraError, estimateBrightness, isIOS, isSafari };
})(window);
