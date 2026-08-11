<?php
require_once __DIR__ . '/db.php';
session_start();

$pageTitle = 'Request Faculty Registration';
$errors = [];
$success = false;

function buildRegistrationFullName(string $first, string $middle, string $last): string {
    return trim($first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last);
}

function registrationDescriptorFromJson(string $json): ?array {
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || count($decoded) !== 128) return null;
    return array_map('floatval', $decoded);
}

function duplicatePendingRegistrationFace(mysqli $conn, array $descriptor): bool {
    if (!tableExists($conn, 'registration_requests')) return false;

    $stmt = $conn->prepare("
        SELECT face_embedding
        FROM registration_requests
        WHERE status = 'Pending' AND face_embedding IS NOT NULL
    ");
    if (!$stmt) return false;
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $existing = json_decode($row['face_embedding'], true);
        if (is_array($existing) && count($existing) === 128 && faceDescriptorDistance($descriptor, array_map('floatval', $existing)) < 0.55) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = trim($_POST['employee_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $securityQuestion = trim($_POST['security_question'] ?? '');
    $securityAnswer = trim($_POST['security_answer'] ?? '');
    $faceDescJson = trim($_POST['face_descriptor'] ?? '');
    $faceImage = trim($_POST['face_image'] ?? '');
    $fullName = buildRegistrationFullName($firstName, $middleName, $lastName);

    if ($employeeId === '') $errors[] = 'Employee ID is required.';
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($department === '') $errors[] = 'Department is required.';
    if ($contact === '') $errors[] = 'Contact number is required.';
    if ($securityQuestion === '') $errors[] = 'Security question is required.';
    if ($securityAnswer === '') $errors[] = 'Security answer is required.';

    $descriptor = registrationDescriptorFromJson($faceDescJson);
    if ($descriptor === null) {
        $errors[] = 'Face registration is required. Please allow the camera and hold still until capture completes.';
    }

    if (empty($errors)) {
        if (!tableExists($conn, 'registration_requests')) {
            $errors[] = 'Registration request table not found. Please run migration.sql.';
        }
    }

    if (empty($errors)) {
        $checks = [
            ["SELECT id FROM users WHERE employee_id = ? AND role = 'faculty' LIMIT 1", $employeeId, 'This Employee ID is already registered.'],
            ["SELECT id FROM users WHERE email = ? AND role = 'faculty' LIMIT 1", $email, 'This email is already registered.'],
            ["SELECT id FROM registration_requests WHERE employee_id = ? AND status = 'Pending' LIMIT 1", $employeeId, 'A pending request with this Employee ID already exists.'],
            ["SELECT id FROM registration_requests WHERE email = ? AND status = 'Pending' LIMIT 1", $email, 'A pending request with this email already exists.'],
        ];
        foreach ($checks as [$sql, $value, $message]) {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $value);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) $errors[] = $message;
                $stmt->close();
            }
        }

        if ($descriptor !== null && checkDuplicateFace($conn, $descriptor, 0) !== null) {
            $errors[] = 'This face is already registered to another faculty account.';
            logActivity($conn, 'DUPLICATE_FACE', "Duplicate registered face blocked for registration request: {$email}");
        }
        if ($descriptor !== null && duplicatePendingRegistrationFace($conn, $descriptor)) {
            $errors[] = 'This face is already registered to another faculty account.';
            logActivity($conn, 'DUPLICATE_FACE', "Duplicate pending face blocked for registration request: {$email}");
        }
    }

    $faceImagePath = null;
    if (empty($errors) && preg_match('/^data:image\/jpeg;base64,/', $faceImage)) {
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'requests';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        $imageData = base64_decode(substr($faceImage, strpos($faceImage, ',') + 1), true);
        if ($imageData !== false) {
            $fileName = 'request_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
            file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $fileName, $imageData);
            $faceImagePath = 'uploads/requests/' . $fileName;
        }
    }

    if (empty($errors)) {
        $answerHash = password_hash($securityAnswer, PASSWORD_DEFAULT);
        $embedding = json_encode($descriptor);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $conn->prepare("
            INSERT INTO registration_requests
                (employee_id, first_name, middle_name, last_name, full_name, email, department,
                 contact_number, security_question, security_answer, face_embedding, face_image, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param(
                'sssssssssssss',
                $employeeId, $firstName, $middleName, $lastName, $fullName, $email, $department,
                $contact, $securityQuestion, $answerHash, $embedding, $faceImagePath, $ip
            );
            if ($stmt->execute()) {
                $success = true;
                pushNotification($conn, 'New Registration Request',
                    "Faculty registration request from {$fullName} ({$email}) is awaiting review.",
                    'info', '/admin/registration_requests.php');
                logActivity($conn, 'FACULTY_REG_SUBMITTED', "One-time face registration request submitted by {$fullName} ({$email})");
            } else {
                $errors[] = 'Could not save your request. Please try again.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request Faculty Registration - Smart Room System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root { --brand-dark:#0d1b2a; --brand-blue:#1a6bcc; }
body {
  min-height:100vh; background:
    linear-gradient(rgba(13,27,42,.80),rgba(13,27,42,.88)),
    url('https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1600&q=80') center/cover fixed;
  font-family:'Segoe UI',system-ui,sans-serif;
}
.card { border:0; border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.12); }
.camera-wrap { position:relative; background:#111; border-radius:12px; overflow:hidden; aspect-ratio:4/3; max-height:280px; }
.camera-wrap video { width:100%; height:100%; object-fit:cover; display:block; }
.face-guide { position:absolute; inset:10% 22%; border:3px solid rgba(255,255,255,.95); border-radius:50%; box-shadow:0 0 0 999px rgba(0,0,0,.32); pointer-events:none; }
.face-guide.stable { border-color:#198754; }
.scan-line { position:absolute; left:16%; right:16%; height:2px; top:15%; background:var(--brand-blue); animation:scan 1.8s linear infinite; opacity:.85; }
@keyframes scan{0%{top:15%}50%{top:82%}100%{top:15%}}
.face-tick { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; }
</style>
</head>
<body class="py-4">
<div class="container" style="max-width:760px;">
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-2" style="width:52px;height:52px;background:var(--brand-blue);">
      <i class="bi bi-person-plus-fill text-white fs-4"></i>
    </div>
    <h4 class="fw-bold text-white mb-0">Request Faculty Registration</h4>
    <p class="text-white-50 small">Complete your information and one-time face registration for admin review.</p>
  </div>

<?php if ($success): ?>
  <div class="card p-4 text-center">
    <i class="bi bi-check-circle-fill text-success fs-1 mb-3"></i>
    <h5 class="fw-bold">Request Submitted</h5>
    <p class="text-muted">Your registration request has been submitted. You will receive an email after administrator approval.</p>
    <a href="/index.php" class="btn btn-outline-primary btn-sm mt-2">Back to Login</a>
  </div>
<?php else: ?>
  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
    <ul class="mb-0 ps-3 mt-1"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
  </div>
  <?php endif; ?>

  <div class="card p-4">
    <form method="POST" id="regForm">
      <input type="hidden" name="face_descriptor" id="face_descriptor">
      <input type="hidden" name="face_image" id="face_image">

      <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-person me-1"></i>Faculty Information</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6"><label class="form-label fw-semibold">Employee ID <span class="text-danger">*</span></label><input type="text" name="employee_id" class="form-control" required value="<?= htmlspecialchars($_POST['employee_id'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Department <span class="text-danger">*</span></label><input type="text" name="department" class="form-control" required value="<?= htmlspecialchars($_POST['department'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label><input type="text" name="contact_number" class="form-control" required value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>"></div>
      </div>
      <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-shield-lock me-1"></i>Account Recovery</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Security Question <span class="text-danger">*</span></label>
          <select name="security_question" class="form-select" required>
            <?php $selectedQuestion = $_POST['security_question'] ?? ''; ?>
            <?php foreach (['What is your mother\'s maiden name?','What was your first school?','What is your favorite subject?','What city were you born in?','What is your favorite teacher\'s name?'] as $q): ?>
              <option value="<?= htmlspecialchars($q) ?>" <?= $selectedQuestion === $q ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">Security Answer <span class="text-danger">*</span></label><input type="text" name="security_answer" class="form-control" required autocomplete="off"></div>
      </div>

      <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-person-bounding-box me-1"></i>One-Time Face Registration</h6>
      <p class="text-muted small">Look directly at the camera. The system will automatically capture your face once centered and stable.</p>
      <div class="camera-wrap mb-2" id="cameraWrap">
        <video id="video" autoplay playsinline webkit-playsinline muted></video>
        <div class="face-guide" id="faceGuide"></div>
        <div class="scan-line" id="scanLine"></div>
        <div class="face-tick d-none" id="faceTick"><i class="bi bi-check-circle-fill text-success" style="font-size:3rem;text-shadow:0 2px 8px rgba(0,0,0,.5)"></i></div>
      </div>
      <div class="alert alert-info d-flex align-items-center gap-2 mb-3" id="faceStatus">
        <div class="spinner-border spinner-border-sm" id="faceSpinner"></div>
        <span id="faceStatusText">Starting camera...</span>
      </div>
      <div class="d-grid mb-3 d-none" id="retryCameraWrap">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="retryCameraBtn">
          <i class="bi bi-arrow-clockwise me-1"></i>Retry Camera
        </button>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
          <i class="bi bi-send me-1"></i> Submit Registration Request
        </button>
      </div>
    </form>
  </div>
<?php endif; ?>

  <p class="text-center text-white-50 small mt-3">Already have an account? <a href="/index.php" class="text-white">Sign in here</a></p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/face_detection.js"></script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="/assets/face-camera.js"></script>
<script>
const video = document.getElementById('video');
const faceStatus = document.getElementById('faceStatus');
const faceText = document.getElementById('faceStatusText');
const spinner = document.getElementById('faceSpinner');
const faceTick = document.getElementById('faceTick');
const scanLine = document.getElementById('scanLine');
const faceGuide = document.getElementById('faceGuide');
const retryWrap = document.getElementById('retryCameraWrap');
const retryBtn = document.getElementById('retryCameraBtn');
const submitBtn = document.getElementById('submitBtn');

let stableFrames = 0, faceApiReady = false, captured = false, descriptorAttempts = 0, cameraHandle = null;
const STABLE_NEEDED = 8;
const MIN_BRIGHTNESS = 45; // 0-255 luma; below this we ask for more light instead of failing silently

function setFaceStatus(msg, type='info', spin=true) {
  faceStatus.className = `alert alert-${type} d-flex align-items-center gap-2 mb-3`;
  faceText.textContent = msg;
  spinner.style.display = spin ? '' : 'none';
}

const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
async function loadModels() {
  await Promise.all([
    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
    faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL),
    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
  ]);
  faceApiReady = true;
}

async function generateDescriptor() {
  if (!faceApiReady) return null;
  const det = await faceapi
    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({scoreThreshold:0.5}))
    .withFaceLandmarks(true)
    .withFaceDescriptor();
  return det ? det.descriptor : null;
}

async function captureDescriptor() {
  if (captured) return;
  captured = true;
  scanLine.style.display = 'none';
  faceTick.classList.remove('d-none');
  faceGuide.classList.add('stable');
  setFaceStatus('🔵 Face detected. Generating biometric template...', 'primary', true);

  const cvs = document.createElement('canvas');
  cvs.width = video.videoWidth || 640;
  cvs.height = video.videoHeight || 480;
  cvs.getContext('2d').drawImage(video, 0, 0, cvs.width, cvs.height);
  document.getElementById('face_image').value = cvs.toDataURL('image/jpeg', 0.88);

  // Retry descriptor generation automatically (slower mobile devices can miss
  // the first pass while the model is still warming up on this frame).
  let desc = await generateDescriptor();
  if (!desc) { descriptorAttempts++; await new Promise(r => setTimeout(r, 250)); desc = await generateDescriptor(); }
  if (!desc) { descriptorAttempts++; await new Promise(r => setTimeout(r, 400)); desc = await generateDescriptor(); }

  if (desc && desc.length === 128) {
    document.getElementById('face_descriptor').value = JSON.stringify(Array.from(desc));
    setFaceStatus('🟢 Face registered once. You may submit the request.', 'success', false);
  } else {
    captured = false;
    faceTick.classList.add('d-none');
    scanLine.style.display = '';
    faceGuide.classList.remove('stable');
    setFaceStatus('🔴 Face recognition data could not be generated. Please hold still, ensure good lighting, and try again.', 'danger', false);
  }
}

function getBox(det) {
  const b = det.boundingBox || det.locationData?.relativeBoundingBox;
  if (!b) return null;
  return { xCenter: b.xCenter ?? (b.xMin + b.width / 2), yCenter: b.yCenter ?? (b.yMin + b.height / 2), width: b.width };
}

const faceDetection = new FaceDetection({ locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/${f}` });
faceDetection.setOptions({ model:'short', minDetectionConfidence:0.68 });
faceDetection.onResults(results => {
  if (captured || !faceApiReady) return;
  const faces = results.detections || [];
  if (faces.length === 0) { stableFrames = 0; setFaceStatus('🟡 No face detected. Please position your face inside the guide.','info'); return; }
  if (faces.length > 1) { stableFrames = 0; setFaceStatus('🔴 Multiple faces detected. Please ensure only one person is visible.','danger'); return; }
  const box = getBox(faces[0]);
  if (box && box.width > 0 && box.width < 0.15) { stableFrames = 0; setFaceStatus('🟡 Move closer to the camera.','warning'); return; }
  const ok = box && box.xCenter > 0.35 && box.xCenter < 0.65 && box.yCenter > 0.25 && box.yCenter < 0.75 && box.width > 0.15;
  if (!ok) { stableFrames = 0; setFaceStatus('🟡 Center your face inside the guide.','warning'); return; }

  const brightness = FaceCamera.estimateBrightness(video);
  if (brightness < MIN_BRIGHTNESS) { stableFrames = 0; setFaceStatus('🟡 Lighting is too dark. Please improve the lighting.','warning'); return; }

  stableFrames++;
  setFaceStatus('🟢 Face detected. Hold still...','success');
  if (stableFrames >= STABLE_NEEDED) captureDescriptor();
});

async function startCamera() {
  retryWrap.classList.add('d-none');
  setFaceStatus('Requesting camera access…', 'info', true);
  try {
    cameraHandle = await FaceCamera.start(video, {
      fps: 8,
      onFrame: () => faceDetection.send({ image: video }),
      onRetryNotice: (msg) => setFaceStatus(msg, 'warning', true),
    });
    if (faceApiReady) {
      setFaceStatus('Camera ready. Look at the camera.', 'info');
    } else {
      setFaceStatus('Camera ready. Loading face recognition model…', 'info', true);
    }
  } catch (e) {
    console.warn('Camera start failed:', e);
    setFaceStatus(e.friendlyMessage || FaceCamera.describeCameraError(e), 'danger', false);
    // iOS Safari caches a permission denial for the page session — a JS
    // retry will just fail again silently. A full reload (after the user
    // fixes the setting) is the only thing that re-prompts there.
    const needsReload = (e && (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError')) && FaceCamera.isIOS();
    retryBtn.innerHTML = needsReload
      ? '<i class="bi bi-arrow-clockwise me-1"></i>Reload Page'
      : '<i class="bi bi-arrow-clockwise me-1"></i>Retry Camera';
    retryBtn.dataset.reload = needsReload ? '1' : '0';
    retryWrap.classList.remove('d-none');
  }
}

retryBtn?.addEventListener('click', () => {
  if (retryBtn.dataset.reload === '1') { window.location.reload(); return; }
  if (cameraHandle) { cameraHandle.stop(); cameraHandle = null; }
  captured = false; stableFrames = 0;
  startCamera();
});

loadModels().then(() => {
  if (cameraHandle) setFaceStatus('Camera ready. Look at the camera.', 'info');
}).catch(() => setFaceStatus('Face recognition model failed to load. Please refresh and try again.', 'danger', false));

startCamera();

window.addEventListener('beforeunload', () => { if (cameraHandle) cameraHandle.stop(); });

document.getElementById('regForm')?.addEventListener('submit', function(e) {
  if (!document.getElementById('face_descriptor').value) {
    e.preventDefault();
    setFaceStatus('Please complete one-time face registration before submitting.','danger',false);
    faceStatus.scrollIntoView({behavior:'smooth'});
  }
});
</script>
</body>
</html>
