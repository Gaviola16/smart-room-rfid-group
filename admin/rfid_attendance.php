<?php
/**
 * admin/rfid_attendance.php
 * RFID Attendance kiosk — no manual selection, no buttons. The faculty
 * simply taps their card on the 13.56 MHz USB reader (which behaves as a
 * keyboard: it types the UID then presses Enter). This page keeps a hidden
 * input permanently focused so the tap is captured automatically, sends the
 * UID to rfid_scan_action.php, and displays a large success/warning card.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');
$pageTitle = 'RFID Attendance';
include __DIR__ . '/../authentication.php';
?>
<style>
  .kiosk-wrap { max-width: 720px; margin: 0 auto; }
  .kiosk-idle {
    border-radius: 20px;
    background: linear-gradient(135deg,#0d1b2a 0%,#1a6bcc 100%);
    color: #fff;
    padding: 3.5rem 2rem;
    text-align: center;
    box-shadow: 0 10px 30px rgba(13,27,42,0.25);
  }
  .kiosk-idle .rfid-icon {
    width: 110px; height: 110px; border-radius: 50%;
    background: rgba(255,255,255,0.12);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 3rem;
    animation: rfidPulse 1.8s ease-in-out infinite;
  }
  @keyframes rfidPulse {
    0%   { box-shadow: 0 0 0 0 rgba(255,255,255,0.35); }
    70%  { box-shadow: 0 0 0 26px rgba(255,255,255,0); }
    100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
  }
  .kiosk-idle h3 { font-weight: 800; letter-spacing: .5px; }
  .kiosk-clock { font-size: 1.1rem; opacity: .85; }
  #rfidHiddenInput {
    position: absolute; opacity: 0; height: 1px; width: 1px; pointer-events: none;
  }
  .result-card {
    border-radius: 20px;
    padding: 2.5rem 2rem;
    text-align: center;
    color: #fff;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18);
  }
  .result-card.success { background: linear-gradient(135deg,#146c43 0%,#198754 100%); }
  .result-card.error   { background: linear-gradient(135deg,#842029 0%,#dc3545 100%); }
  .result-card .result-icon { font-size: 3.5rem; margin-bottom: .75rem; }
  .result-card .result-title { font-size: 1.6rem; font-weight: 800; margin-bottom: .5rem; }
  .result-card .result-message { font-size: 1rem; opacity: .92; margin-bottom: 1rem; line-height: 1.4; }
  .result-row { font-size: 1.05rem; margin-bottom: .35rem; }
  .result-row .label { opacity: .8; font-size: .8rem; text-transform: uppercase; letter-spacing: .5px; display:block; }
  .kiosk-focus-hint { font-size: .8rem; opacity: .7; margin-top: .5rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front-fill me-2 text-primary"></i>RFID Attendance</h5>
    <div class="text-muted small">Tap a faculty RFID card to automatically Check In / Check Out</div>
  </div>
  <a href="rfid_management.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-gear me-1"></i>Manage Cards
  </a>
</div>

<div class="kiosk-wrap" onclick="focusRfidInput()">

  <div id="idleState" class="kiosk-idle">
    <div class="rfid-icon"><i class="bi bi-wifi"></i></div>
    <h3>Waiting for RFID Card…</h3>
    <div class="kiosk-clock" id="kioskClock">--:--:--</div>
    <div class="kiosk-focus-hint">Tap your card on the reader. No typing required.</div>
  </div>

  <div id="resultState" class="d-none"></div>

  <input type="text" id="rfidHiddenInput" autocomplete="off" autofocus>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

const rfidInput   = document.getElementById('rfidHiddenInput');
const idleState   = document.getElementById('idleState');
const resultState = document.getElementById('resultState');
let processing = false;
let resetTimer = null;

function focusRfidInput() {
  rfidInput.focus();
}
focusRfidInput();
// Keep the cursor pinned to the hidden input no matter what the admin clicks.
document.addEventListener('click', focusRfidInput);
window.addEventListener('blur', () => setTimeout(focusRfidInput, 50));
setInterval(focusRfidInput, 1500);

function updateClock() {
  const el = document.getElementById('kioskClock');
  if (el) el.textContent = new Date().toLocaleString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
}
updateClock();
setInterval(updateClock, 1000);

rfidInput.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();

  // Strip stray control/invisible characters some USB readers inject
  // alongside the UID, on top of the plain trim (the server re-validates
  // this independently — this is just to avoid submitting obvious junk).
  const uid = rfidInput.value.replace(/[\x00-\x1F\x7F]/g, '').trim();
  rfidInput.value = '';
  if (!uid || processing) return;

  processing = true;
  submitScan(uid);
});

async function submitScan(uid) {
  try {
    const res = await fetch('rfid_scan_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'uid=' + encodeURIComponent(uid)
    });
    const data = await res.json();
    showResult(data);
  } catch (err) {
    showResult({ status: 'error', message: 'Connection error. Please try again.' });
  }
}

function showResult(data) {
  idleState.classList.add('d-none');
  resultState.classList.remove('d-none');

  const isSuccess = data.status === 'success';
  const isDuplicate = data.status === 'duplicate';
  const cardClass = isSuccess ? 'success' : 'error';
  const icon = isSuccess ? 'bi-check-circle-fill' : (isDuplicate ? 'bi-hourglass-split' : 'bi-x-circle-fill');

  // `title` is the short headline (e.g. "Attendance Rejected"); `message` is
  // the longer explanation shown underneath it. Older/unspecified responses
  // only send `message`, so that still works fine as a standalone headline.
  const titleText   = data.title || data.message || (isSuccess ? 'Success' : (isDuplicate ? 'Please Wait' : 'Error'));
  const messageText = (data.title && data.message && data.message !== data.title) ? data.message : '';

  let rows = '';
  if (data.faculty_name) {
    rows += `<div class="result-row"><span class="label">Faculty</span>${escapeHtml(data.faculty_name)}</div>`;
  }
  if (data.subject) {
    rows += `<div class="result-row"><span class="label">Subject</span>${escapeHtml(data.subject)}${data.section ? ' — ' + escapeHtml(data.section) : ''}</div>`;
  }
  if (data.room) {
    rows += `<div class="result-row"><span class="label">Room</span>${escapeHtml(data.room)}</div>`;
  }
  if (data.employee_id || data.department) {
    rows += `<div class="result-row"><span class="label">Faculty ID / Department</span>${escapeHtml(data.employee_id || '—')} · ${escapeHtml(data.department || '—')}</div>`;
  }
  if (data.rfid_uid) {
    rows += `<div class="result-row"><span class="label">RFID UID</span><code>${escapeHtml(data.rfid_uid)}</code></div>`;
  }
  if (data.current_date || data.current_time) {
    rows += `<div class="result-row"><span class="label">Date / Time</span>${escapeHtml(data.current_date || '')} ${escapeHtml(data.current_time || '')}</div>`;
  }

  resultState.innerHTML = `
    <div class="result-card ${cardClass}">
      <div class="result-icon"><i class="bi ${icon}"></i></div>
      <div class="result-title">${escapeHtml(titleText)}</div>
      ${messageText ? `<div class="result-message">${escapeHtml(messageText)}</div>` : ''}
      ${rows}
    </div>
  `;

  clearTimeout(resetTimer);
  resetTimer = setTimeout(resetKiosk, isSuccess ? 5000 : 4000);
}

function resetKiosk() {
  resultState.classList.add('d-none');
  resultState.innerHTML = '';
  idleState.classList.remove('d-none');
  processing = false;
  focusRfidInput();
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}
</script>
</body></html>
