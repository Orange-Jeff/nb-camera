/*
 Version 6.4.1 (2025-11-13 19:45) - CRITICAL FIX: Removed all references to deleted btnStart/btnStop that were breaking camera initialization. Moved Scan/Flip/Mirror buttons to separate row.
 Version 6.4.0 (2025-11-13 19:30) - Added allow_public_access option to bypass role checks, added allow_device_select option to show/hide camera dropdown, fixed photoCountdown to read from dataset.
 Version 6.3.4 (2025-11-13) - CRITICAL FIX: Corrected dataset reading bug (root vs ui element), added photo countdown timer (3/5/10s), digital stopwatch-style recording timer updates 10x per second.
 Version 6.3.3 (2025-11-13) - Added flash effect for photos, notification messages for photo/video capture, camera always on (no stop).
 Version 6.3.2 (2025-11-13) - Removed start/stop camera buttons, auto-start camera on init, added red recording indicator show/hide, fixed download/preview/profile-pic button visibility after capture.
 Version 6.3 (2025-11-13) - Distribution hardening: robust device detection (devicechange, visibility, retry/backoff), Retry/Flip controls, version bump.
 Version 0.2.0 (2025-11-13) - Auto performance tuning (slow machine heuristics), upload queue with limited concurrency, unified chunk handling.
 Version 0.1.0 (2025-11-13) - Initial frontend logic.
 Core features: device detection, photo capture, video recording (single/segments), timer, preview, download, Media Library upload, set avatar.
*/

(function(){
  'use strict';

  function qs(el, s){ return el.querySelector(s); }
  function qsa(el, s){ return Array.from(el.querySelectorAll(s)); }
  function fmtTime(sec){ sec = Math.max(0, Math.floor(sec)); const m = Math.floor(sec/60); const s = sec%60; return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`; }

  // Incrementing filename index in localStorage per base
  function nextFilename(base, ext){
    try {
      const key = `nbc_idx_${base}`;
      const v = parseInt(localStorage.getItem(key) || '0', 10) + 1;
      localStorage.setItem(key, String(v));
      return `${base}-${v}.${ext}`;
    } catch(e) {
      return `${base}-${Date.now()}.${ext}`;
    }
  }

  // Upload a Blob to the WP Media Library via REST
  async function uploadBlobToMedia(blob, filename, title){
    if (!NBCAMERA || !NBCAMERA.rest) throw new Error('REST not localized');
    const url = NBCAMERA.rest.root.replace(/\/$/, '') + NBCAMERA.rest.media;
    const fd = new FormData();
    fd.append('file', blob, filename);
    if (title) fd.append('title', title);

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-WP-Nonce': NBCAMERA.rest.nonce },
      body: fd
    });
    if (!res.ok) throw new Error(`Upload failed: ${res.status}`);
    return await res.json(); // returns media object
  }

  // Simple upload queue to limit concurrent uploads
  class UploadQueue {
    constructor(maxConcurrent){
      this.max = Math.max(1, maxConcurrent || 2);
      this.active = 0;
      this.q = [];
    }
    enqueue(task){
      return new Promise((resolve, reject) => {
        this.q.push({ task, resolve, reject });
        this._drain();
      });
    }
    _drain(){
      while (this.active < this.max && this.q.length){
        const { task, resolve, reject } = this.q.shift();
        this.active++;
        Promise.resolve()
          .then(task)
          .then(res => { this.active--; resolve(res); this._drain(); })
          .catch(err => { this.active--; reject(err); this._drain(); });
      }
    }
    setMax(n){ this.max = Math.max(1, n|0); this._drain(); }
  }

  async function setAvatar(attachmentId){
    const url = NBCAMERA.rest.root.replace(/\/$/, '') + NBCAMERA.rest.avatar;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': NBCAMERA.rest.nonce
      },
      body: JSON.stringify({ attachment_id: attachmentId })
    });
    if (!res.ok) throw new Error('Failed to set avatar');
    return await res.json();
  }

  // Camera UI controller
  class CameraUI {
    constructor(root){
      this.root = root;
      this.uiElement = qs(root, '.nbc-ui');
      this.state = {
        stream: null,
        deviceId: null,
        facing: 'user',
        mediaRecorder: null,
        recChunks: [],
        recStartTs: 0,
        recTimer: null,
        stopped: true,
        mode: root.dataset.mode || 'both',
        maxTime: parseInt(root.dataset.maxTime || '0',10) || 0,
        showTimer: root.dataset.showTimer === '1',
        showDownload: root.dataset.showDownload === '1',
        preview: root.dataset.preview === '1',
        saveToMedia: root.dataset.saveToMedia === '1',
        recordingMode: root.dataset.recordingMode || 'single',
        chunkMs: (NBCAMERA && NBCAMERA.options && NBCAMERA.options.chunk_ms) ? parseInt(NBCAMERA.options.chunk_ms,10) : 5000,
        allowSetProfilePic: root.dataset.allowSetProfilePic === '1',
        photoCountdown: parseInt(root.dataset.photoCountdown || '0',10),
        allowDeviceSelect: root.dataset.allowDeviceSelect === '1',
        autoPerf: !!(NBCAMERA && NBCAMERA.options && NBCAMERA.options.auto_perf_tuning),
        tuned: false,
        desiredRes: { w: 960, h: 540 },
        retryCount: 0
      };

      const maxConc = (NBCAMERA && NBCAMERA.options && NBCAMERA.options.max_upload_concurrency) ? parseInt(NBCAMERA.options.max_upload_concurrency,10) : 2;
      this.uploadQueue = new UploadQueue(maxConc);

      const ui = this.uiElement;
      this.el = {
        deviceSelect: qs(ui, '.nbc-device-select'),
        video: qs(ui, '.nbc-video'),
        canvas: qs(ui, '.nbc-canvas'),
        btnPhoto: qs(ui, '.nbc-photo'),
        btnRec: qs(ui, '.nbc-rec'),
        btnRecStop: qs(ui, '.nbc-rec-stop'),
        timer: qs(ui, '.nbc-timer'),
        countdown: qs(ui, '.nbc-countdown'),
        download: qs(ui, '.nbc-download'),
        upload: qs(ui, '.nbc-upload'),
        setAvatar: qs(ui, '.nbc-set-avatar'),
        preview: qs(ui, '.nbc-preview'),
        modal: root.closest('.nbc-modal'),
        btnRetry: qs(ui, '.nbc-retry'),
        btnFlip: qs(ui, '.nbc-flip'),
        btnMirror: qs(ui, '.nbc-mirror'),
        recIndicator: qs(ui, '.nbc-rec-indicator')
      };

      this.bind();
      this.initModalBehavior();

      // Hide device select row if admin disabled it
      if (!this.state.allowDeviceSelect && this.el.deviceSelect) {
        const row = this.el.deviceSelect.closest('.nbc-row');
        if (row) row.style.display = 'none';
      }

      // Auto-start camera after device detection
      this.detectDevices().then(() => {
        if (this.el.deviceSelect.options.length > 0) {
          this.startCamera();
        }
      });
    }

    // Assess performance and adjust settings once per session
    async applyAutoPerfTuningOnce(){
      if (!this.state.autoPerf || this.state.tuned) return;

      // Heuristics based on hardware and memory
      const cores = (navigator.hardwareConcurrency || 2);
      const mem = (navigator.deviceMemory || 2);
      const isLow = (cores <= 4) || (mem <= 4);

      if (isLow) {
        this.state.recordingMode = 'single'; // avoid frequent chunk handling
        this.state.chunkMs = Math.max(this.state.chunkMs, 8000); // bigger chunks if needed later
        this.uploadQueue.setMax(1);
        this.state.desiredRes = { w: 640, h: 360 };
      } else {
        this.state.desiredRes = { w: 960, h: 540 };
      }

      // Optional: measure real FPS briefly and refine
      try {
        const fps = await this.measureFpsFor(1500);
        if (fps && fps < 20) {
          this.state.recordingMode = 'single';
          this.state.chunkMs = Math.max(this.state.chunkMs, 10000);
          this.uploadQueue.setMax(1);
          this.state.desiredRes = { w: 640, h: 360 };
        }
      } catch(_){}

      this.state.tuned = true;
    }

    measureFpsFor(ms){
      return new Promise((resolve) => {
        const video = this.el.video;
        if (!('requestVideoFrameCallback' in HTMLVideoElement.prototype)) {
          // fallback: approximate using rAF
          let frames = 0; let start = performance.now();
          const tick = (t)=>{
            frames++;
            if (t - start >= ms) {
              const fps = frames / ((t - start)/1000);
              resolve(fps);
            } else { requestAnimationFrame(tick); }
          };
          requestAnimationFrame(tick);
          return;
        }
        let frames = 0; const start = performance.now();
        const step = () => {
          frames++;
          const t = performance.now();
          if (t - start >= ms) {
            const fps = frames / ((t - start)/1000);
            resolve(fps);
          } else {
            video.requestVideoFrameCallback(step);
          }
        };
        video.requestVideoFrameCallback(step);
      });
    }

  // ========================= Webcam detection (well-documented) =========================
    // Browsers may hide device labels until permission is granted via getUserMedia.
    // Strategy:
    // 1) Attempt to get a temporary stream with { video: true } to unlock labels.
    // 2) enumerateDevices() and filter for videoinput.
    // 3) Populate the select; if deviceId stored previously, pre-select.
    // 4) On selection, (re)start the stream with the chosen deviceId.
    // Edge cases handled:
    // - Permissions denied -> we still populate devices (labels may be blank) and disable start.
    // - No devices -> disable controls and show friendly message.
    // - iOS/Safari quirks -> ensure playsinline and muted on video, and use user-facing camera fallback.
    async detectDevices(){
      try {
        // Try to unlock labels – stop the stream right after
        const tmp = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        tmp.getTracks().forEach(t=>t.stop());
      } catch(e) {
        // Permission denied or not yet granted – continue; labels may be empty
        console.debug('getUserMedia preflight failed (non-fatal):', e);
      }
      let devices = [];
      try {
        const all = await navigator.mediaDevices.enumerateDevices();
        devices = all.filter(d => d.kind === 'videoinput');
      } catch(e) {
        console.error('enumerateDevices failed:', e);
      }

      const sel = this.el.deviceSelect;
      sel.innerHTML = '';
      if (!devices.length) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'No cameras found';
        sel.appendChild(opt);
        this.disableControls(true);
        // Backoff retry a few times to handle permission UI pauses
        if (this.state.retryCount < 5) {
          const delay = Math.min(2000, 400 * Math.pow(2, this.state.retryCount++));
          setTimeout(() => this.detectDevices(), delay);
        }
        // Show Flip if no device IDs are available (mobile fallback)
        if (this.el.btnFlip) this.el.btnFlip.hidden = false;
        return;
      }

      this.state.retryCount = 0;
      devices.forEach((d, i) => {
        const opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.textContent = d.label || `Camera ${i+1}`;
        sel.appendChild(opt);
      });

      const saved = localStorage.getItem('nbc_last_device');
      if (saved && devices.some(d => d.deviceId === saved)) {
        sel.value = saved;
        this.state.deviceId = saved;
      } else {
        this.state.deviceId = devices[0].deviceId;
      }

      // Flip button only relevant if one or fewer devices (mobile front/back)
      if (this.el.btnFlip) this.el.btnFlip.hidden = !(devices.length <= 1);
    }
    // =====================================================================================

    bind(){
      // Modal Open/Close if present
      const root = this.root.closest('.nbc-root');
      if (root) {
        const openBtn = root.querySelector('.nbc-open');
        const modal = root.querySelector('.nbc-modal');
        if (openBtn && modal) {
          openBtn.addEventListener('click', () => modal.hidden = false);
          qsa(modal, '[data-nbc-close]').forEach(el => el.addEventListener('click', () => modal.hidden = true));
        }
      }

      this.el.deviceSelect.addEventListener('change', () => {
        this.state.deviceId = this.el.deviceSelect.value;
        localStorage.setItem('nbc_last_device', this.state.deviceId || '');
        if (this.state.stream) {
          // If camera is running, restart with the new device
          this.startCamera(true);
        }
      });

      if (this.el.btnRetry) this.el.btnRetry.addEventListener('click', () => { this.state.retryCount = 0; this.detectDevices(); });
      if (this.el.btnFlip) this.el.btnFlip.addEventListener('click', async () => {
        this.state.facing = (this.state.facing === 'user') ? 'environment' : 'user';
        this.state.deviceId = null; // switch to facingMode-based selection
        await this.startCamera(true);
      });
      if (this.el.btnMirror) this.el.btnMirror.addEventListener('click', () => {
        const current = this.el.video.style.transform || '';
        this.el.video.style.transform = current.includes('scaleX(-1)') ? '' : 'scaleX(-1)';
      });

      if (this.el.btnPhoto) this.el.btnPhoto.addEventListener('click', () => this.takePhoto());
      if (this.el.btnRec) this.el.btnRec.addEventListener('click', () => this.startRecording());
      if (this.el.btnRecStop) this.el.btnRecStop.addEventListener('click', () => this.stopRecording());

      if (this.state.showDownload && this.el.download) {
        this.el.download.hidden = false;
      }
      if (NBCAMERA && NBCAMERA.user && NBCAMERA.user.can_upload && this.el.upload) {
        this.el.upload.hidden = false;
      }
      if (this.state.allowSetProfilePic && this.el.setAvatar) {
        this.el.setAvatar.hidden = false;
      }

      if (this.el.upload) this.el.upload.addEventListener('click', () => this.uploadLast());
      if (this.el.setAvatar) this.el.setAvatar.addEventListener('click', () => this.setAvatarLast());

      // Redetect on device changes and when tab regains visibility (after permission prompts)
      if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
        navigator.mediaDevices.addEventListener('devicechange', () => this.detectDevices());
      }
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          // If we previously had no devices or labels, try again
          this.detectDevices();
        }
      });
      window.addEventListener('focus', () => this.detectDevices());

      this.detectDevices();
    }

    initModalBehavior(){
      // No-op for now; modal wired in bind().
    }

    async startCamera(restart=false){
      try {
        if (this.state.stream) this.stopCamera();
        const vCons = this.state.deviceId
          ? { deviceId: { exact: this.state.deviceId }, width: { ideal: this.state.desiredRes.w }, height: { ideal: this.state.desiredRes.h } }
          : { facingMode: this.state.facing || 'user', width: { ideal: this.state.desiredRes.w }, height: { ideal: this.state.desiredRes.h } };
        const stream = await navigator.mediaDevices.getUserMedia({ video: vCons, audio: false });
        this.state.stream = stream;
        this.el.video.srcObject = stream;
        if (this.el.btnPhoto) this.el.btnPhoto.disabled = false;
        if (this.el.btnRec) this.el.btnRec.disabled = false;

        // Apply performance tuning once we have frames flowing
        setTimeout(() => this.applyAutoPerfTuningOnce(), 400);
        // Refresh device labels after permission grants
        setTimeout(() => this.detectDevices(), 800);
      } catch(e) {
        console.error('startCamera failed', e);
        this.showNotification('❌ Unable to start camera. Check permissions.');
      }
    }

    stopCamera(){
      if (this.state.mediaRecorder && this.state.mediaRecorder.state !== 'inactive') {
        this.state.mediaRecorder.stop();
      }
      if (this.state.stream) {
        this.state.stream.getTracks().forEach(t=>t.stop());
        this.state.stream = null;
      }
      this.el.video.srcObject = null;
      if (this.el.btnPhoto) this.el.btnPhoto.disabled = true;
      if (this.el.btnRec) this.el.btnRec.disabled = true;
      if (this.el.btnRecStop) this.el.btnRecStop.disabled = true;
      this.stopTimer();
    }

    takePhoto(){
      // If countdown enabled, show countdown first
      if (this.state.photoCountdown > 0) {
        this.showCountdown(this.state.photoCountdown, () => this.capturePhoto());
      } else {
        this.capturePhoto();
      }
    }

    capturePhoto(){
      const video = this.el.video;
      const canvas = this.el.canvas;
      const w = video.videoWidth; const h = video.videoHeight;
      if (!w || !h) return;
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, w, h);

      // Visual flash feedback for snapshot
      this.flashVideo();

      canvas.toBlob(blob => {
        if (!blob) return;
        this.lastBlob = new Blob([blob], { type: 'image/jpeg' });
        this.lastType = 'photo';
        const name = nextFilename(NBCAMERA.options.filename_base || 'capture', 'jpg');
        this.lastFilename = name;

        // Show notification
        this.showNotification('📷 Photo captured!');

        if (this.state.preview) {
          this.showPreview(URL.createObjectURL(this.lastBlob), 'image');
        }
        if (this.state.showDownload && this.el.download) {
          this.setDownloadURL(this.lastBlob, name);
          this.el.download.hidden = false;
        }
        // Show upload/avatar buttons if appropriate
        if (NBCAMERA && NBCAMERA.user && NBCAMERA.user.can_upload && this.el.upload) {
          this.el.upload.hidden = false;
        }
        if (this.state.allowSetProfilePic && this.el.setAvatar) {
          this.el.setAvatar.hidden = false;
        }
      }, 'image/jpeg', 0.92);
    }

    startRecording(){
      if (!this.state.stream) return;
      this.state.recChunks = [];
      const mr = new MediaRecorder(this.state.stream, { mimeType: (MediaRecorder.isTypeSupported('video/webm;codecs=vp9') ? 'video/webm;codecs=vp9' : 'video/webm') });
      this.state.mediaRecorder = mr;
      this.state.recStartTs = Date.now();
      this.state.stopped = false;
      this.el.btnRec.disabled = true;
      this.el.btnRecStop.disabled = false;
      if (this.state.showTimer) this.startTimer();

      // Show recording indicator
      if (this.el.recIndicator) this.el.recIndicator.hidden = false;

      mr.ondataavailable = async (e) => {
        if (!e.data || e.data.size === 0) return;
        if (this.state.recordingMode === 'segments' && NBCAMERA.user.can_upload && NBCAMERA.options.save_to_media) {
          // Upload each chunk via queue to limit concurrency
          const fname = nextFilename(NBCAMERA.options.filename_base || 'capture', 'webm');
          this.uploadQueue.enqueue(() => uploadBlobToMedia(e.data, fname, fname.replace(/\.[^.]+$/, ''))).catch(err => {
            console.error('Segment upload failed', err);
          });
        } else {
          this.state.recChunks.push(e.data);
        }
      };
      mr.onstop = () => {
        this.el.btnRec.disabled = false;
        this.el.btnRecStop.disabled = true;
        this.stopTimer();

        // Hide recording indicator
        if (this.el.recIndicator) this.el.recIndicator.hidden = true;

        if (this.state.recordingMode === 'single') {
          const blob = new Blob(this.state.recChunks, { type: 'video/webm' });
          this.lastBlob = blob;
          this.lastType = 'video';
          const name = nextFilename(NBCAMERA.options.filename_base || 'capture', 'webm');
          this.lastFilename = name;

          // Show notification for completed video
          this.showNotification('🎥 Video recording complete!');

          if (this.state.showDownload && this.el.download) {
            this.setDownloadURL(blob, name);
            this.el.download.hidden = false;
          }
          if (this.state.preview) {
            this.showPreview(URL.createObjectURL(blob), 'video');
          }
          // Show upload/avatar buttons if appropriate
          if (NBCAMERA && NBCAMERA.user && NBCAMERA.user.can_upload && this.el.upload) {
            this.el.upload.hidden = false;
          }
          if (this.state.allowSetProfilePic && this.el.setAvatar) {
            this.el.setAvatar.hidden = false;
          }
        }
      };

  const slice = this.state.recordingMode === 'segments' ? (this.state.chunkMs || 5000) : 0;
      if (slice > 0) mr.start(slice); else mr.start();

      if (this.state.maxTime > 0) {
        setTimeout(() => {
          if (mr.state !== 'inactive') this.stopRecording();
        }, this.state.maxTime * 1000);
      }
    }

    stopRecording(){
      if (this.state.mediaRecorder && this.state.mediaRecorder.state !== 'inactive') {
        this.state.mediaRecorder.stop();
      }
    }

    startTimer(){
      this.el.timer.hidden = false;
      const tick = () => {
        const elapsed = Math.floor((Date.now() - this.state.recStartTs)/1000);
        this.el.timer.textContent = fmtTime(elapsed);
      };
      tick();
      this.state.recTimer = setInterval(tick, 100); // Update 10x per second for smooth display
    }

    stopTimer(){
      if (this.state.recTimer) clearInterval(this.state.recTimer);
      this.state.recTimer = null;
      if (!this.state.showTimer) {
        this.el.timer.hidden = true;
      }
      this.el.timer.textContent = '00:00';
    }

    setDownloadURL(blob, filename){
      const url = URL.createObjectURL(blob);
      this.el.download.href = url;
      this.el.download.download = filename;
      this.el.download.textContent = `Download ${filename}`;
      this.el.download.hidden = false;
    }

    showPreview(url, kind){
      if (!this.state.preview) return;
      const wrap = this.el.preview;
      wrap.innerHTML = '';
      if (kind === 'image') {
        const img = new Image();
        img.src = url;
        img.style.maxWidth = '100%';
        wrap.appendChild(img);
      } else {
        const v = document.createElement('video');
        v.src = url; v.controls = true; v.style.maxWidth = '100%';
        wrap.appendChild(v);
      }
      wrap.hidden = false;
    }

    async uploadLast(){
      if (!this.lastBlob) return;
      if (!NBCAMERA.user.can_upload) return alert('You do not have permission to upload.');
      const fname = this.lastFilename || nextFilename(NBCAMERA.options.filename_base || 'capture', this.lastType === 'photo' ? 'jpg' : 'webm');
      try {
        const media = await uploadBlobToMedia(this.lastBlob, fname, fname.replace(/\.[^.]+$/, ''));
        this.lastAttachmentId = media && media.id;
        alert('Saved to Media Library.');
      } catch(e){
        console.error(e);
        alert('Upload failed.');
      }
    }

    async setAvatarLast(){
      try {
        if (!this.lastAttachmentId) {
          await this.uploadLast();
        }
        if (!this.lastAttachmentId) return;
        await setAvatar(this.lastAttachmentId);
        alert('Profile picture updated.');
      } catch(e){
        console.error(e);
        alert('Failed to update profile picture.');
      }
    }

    disableControls(disabled){
      [this.el.btnPhoto, this.el.btnRec, this.el.btnRecStop].forEach(b => { if (b) b.disabled = !!disabled; });
    }

    // Show countdown before photo capture
    showCountdown(seconds, callback) {
      if (!this.el.countdown) return callback();

      let remaining = seconds;
      this.el.countdown.textContent = remaining;
      this.el.countdown.hidden = false;

      const interval = setInterval(() => {
        remaining--;
        if (remaining > 0) {
          this.el.countdown.textContent = remaining;
        } else {
          clearInterval(interval);
          this.el.countdown.hidden = true;
          callback();
        }
      }, 1000);
    }

    // Visual flash feedback for photo capture
    flashVideo() {
      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:absolute;inset:0;background:#fff;opacity:0.8;pointer-events:none;border-radius:4px;';
      this.el.video.parentElement.appendChild(overlay);
      setTimeout(() => {
        overlay.style.transition = 'opacity 0.3s';
        overlay.style.opacity = '0';
        setTimeout(() => overlay.remove(), 300);
      }, 100);
    }

    // Show notification message
    showNotification(message) {
      let notif = this.uiElement.querySelector('.nbc-notification');
      if (!notif) {
        notif = document.createElement('div');
        notif.className = 'nbc-notification';
        this.uiElement.appendChild(notif);
      }
      notif.textContent = message;
      notif.style.display = 'block';
      notif.style.opacity = '1';

      clearTimeout(this.notifTimeout);
      this.notifTimeout = setTimeout(() => {
        notif.style.opacity = '0';
        setTimeout(() => { notif.style.display = 'none'; }, 300);
      }, 3000);
    }
  }  function initRoot(root){
    const ui = qs(root, '.nbc-ui');
    if (!ui) return;
    new CameraUI(root); // Pass root, not ui - data attributes are on root
  }

  function init(){
    // Modal openers already wired per-instance. Initialize all roots on DOM ready.
    document.querySelectorAll('.nbc-root').forEach(root => initRoot(root));
  }

  document.addEventListener('DOMContentLoaded', init);
})();
