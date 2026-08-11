<?php
/**
 * admin/rfid_test.php
 * RFID Test Mode — scan a card and see its UID before enabling attendance.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');
ensureRfidTables($conn);
$pageTitle = 'RFID Test Mode';
include __DIR__ . '/../authentication.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-wifi me-2 text-warning"></i>RFID Test Mode</h5>
    <div class="text-muted small">Test your RFID reader and preview card UIDs before assigning them</div>
  </div>
  <a href="rfid_management.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back to Management
  </a>
</div>

<div class="row g-3">
  <!-- Reader Status -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <span id="readerIcon" class="fs-1">📡</span>
        </div>
        <h6 class="fw-bold">RFID Reader Status</h6>
        <div class="badge bg-secondary fs-6 px-3 py-2 mb-3" id="readerStatus">Checking…</div>
        <div class="text-muted small" id="readerMsg">Attempting to connect to RFID reader…</div>
        <hr>
        <button class="btn btn-outline-primary btn-sm" onclick="checkReaderStatus()">
          <i class="bi bi-arrow-clockwise me-1"></i>Refresh Status
        </button>
      </div>
    </div>
  </div>

  <!-- Scan Tester -->
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-upc-scan me-1"></i>Card Scanner</div>
      <div class="card-body">
        <p class="text-muted small">
          Tap an RFID card on the reader (or enter a UID manually to simulate a scan).
          The system will display card information without recording attendance.
        </p>

        <div class="input-group mb-3">
          <input type="text" id="manualUid" class="form-control font-monospace"
                 placeholder="Scan card or type UID here…" autofocus>
          <button class="btn btn-primary" onclick="testScan()">
            <i class="bi bi-play-fill me-1"></i>Test Scan
          </button>
        </div>

        <div id="scanResult" class="d-none">
          <div class="alert mb-0" id="scanAlert">
            <div class="d-flex gap-3 align-items-start">
              <i class="bi fs-3" id="scanIcon"></i>
              <div>
                <strong id="scanTitle"></strong>
                <div class="small" id="scanBody"></div>
              </div>
            </div>
          </div>
        </div>

        <div id="scanHistory" class="mt-3">
          <h6 class="fw-semibold small text-muted mb-2">Scan History (this session)</h6>
          <div id="historyList"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

const history = [];
let testScanInFlight = false;

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

async function testScan() {
  // Guard against double-Enter / double-click firing two lookups for the
  // same tap, same as the live attendance kiosk does.
  if (testScanInFlight) return;

  const uidField = document.getElementById('manualUid');
  const uid = uidField.value.replace(/[\x00-\x1F\x7F]/g, '').trim();
  if (!uid) { alert('Please enter or scan a UID.'); return; }

  testScanInFlight = true;
  let data;
  try {
    const res = await fetch('rfid_lookup.php?uid=' + encodeURIComponent(uid));
    data = await res.json();
  } catch (err) {
    testScanInFlight = false;
    alert('Connection error. Please try again.');
    return;
  }
  testScanInFlight = false;

  const resultDiv   = document.getElementById('scanResult');
  const alert_      = document.getElementById('scanAlert');
  const icon        = document.getElementById('scanIcon');
  const title       = document.getElementById('scanTitle');
  const body        = document.getElementById('scanBody');

  resultDiv.classList.remove('d-none');

  // All values below can originate from user input (the typed/scanned UID)
  // or from database content (faculty name, employee ID) — every one is
  // escaped before insertion since this uses innerHTML.
  if (data.found) {
    alert_.className = 'alert alert-success mb-0';
    icon.className   = 'bi bi-check-circle-fill text-success fs-3';
    title.textContent = '✓ Card Recognised';
    body.innerHTML   = `UID: <code>${escapeHtml(uid)}</code> — Assigned to <strong>${escapeHtml(data.faculty_name)}</strong> (${escapeHtml(data.employee_id)})<br>Status: ${escapeHtml(data.card_status)}`;
  } else {
    alert_.className = 'alert alert-warning mb-0';
    icon.className   = 'bi bi-question-circle-fill text-warning fs-3';
    title.textContent = '⚠ Unknown Card';
    body.innerHTML   = `UID: <code>${escapeHtml(uid)}</code> — Not assigned to any faculty member.<br>
      <a href="rfid_management.php" class="btn btn-sm btn-primary mt-2">Assign this card</a>`;
  }

  // Add to session history
  history.unshift({ uid, time: new Date().toLocaleTimeString(), found: data.found, name: data.faculty_name || 'Unknown' });
  const list = document.getElementById('historyList');
  list.innerHTML = history.map(h =>
    `<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1 small">
       <span><code>${escapeHtml(h.uid)}</code> — ${escapeHtml(h.name)}</span>
       <span class="badge bg-${h.found?'success':'warning text-dark'}">${escapeHtml(h.time)}</span>
     </div>`
  ).join('');

  uidField.value = '';
  uidField.focus();
}

// Allow Enter key to trigger scan (keydown + preventDefault, matching the
// live attendance kiosk, instead of the deprecated 'keypress' event).
document.getElementById('manualUid').addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  testScan();
});

function checkReaderStatus() {
  // Simulated status check — replace with actual hardware ping endpoint if available
  const statusEl = document.getElementById('readerStatus');
  const msgEl    = document.getElementById('readerMsg');
  const iconEl   = document.getElementById('readerIcon');
  statusEl.textContent = 'Simulated / Manual Mode';
  statusEl.className   = 'badge bg-warning text-dark fs-6 px-3 py-2 mb-3';
  iconEl.textContent   = '📡';
  msgEl.textContent    = 'Hardware RFID reader connection requires server-side integration. Use manual UID entry for testing.';
}
checkReaderStatus();
</script>
</body></html>
