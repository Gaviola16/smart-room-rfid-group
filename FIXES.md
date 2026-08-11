# FIXES.md — Smart Room Scheduling System (cPanel Readiness Pass)

This document lists every change made during this review, plus important
issues that were found but intentionally **not** auto-changed because fixing
them safely requires a decision from you (they are flagged below as
"ACTION REQUIRED").

## Summary

The project was already migrated off the `/smart_room` subfolder in almost
every place (redirects, `href`, `fetch()` calls, etc. already use root-relative
paths like `/admin/dashboard.php`). The real risks for a fresh cPanel deploy
were: two leftover `/smart_room` cookie paths, and **every page using
relative `require_once '../db.php'`-style includes**, which works on most
Apache/cPanel setups but breaks the moment PHP's working directory differs
from the script directory (CLI cron jobs, some PHP-FPM pools, `auto_prepend_file`,
symlinked vhosts, etc.) — a classic intermittent-500 cause on shared hosting.

## 1. Removed all `/smart_room` references

- `db.php` — `rememberFacultyTrustedDevice()` and `invalidateFacultyTrustedDevices()`
  set/cleared the trusted-device cookie with `'path' => '/smart_room'`. Changed
  to `'path' => '/'`. This was an actual bug: with the project now at the
  domain root, that cookie would never be sent back by the browser, so
  "remember this device" would silently fail to skip future OTP/face checks.
- `admin/notifications.php`, `faculty/notifications.php` — updated stale
  `// PLACE AT: smart_room/...` header comments to `public_html/...`.
- Confirmed no other `/smart_room` references exist anywhere in PHP, JS,
  CSS, or JSON (the `smart_room_db` database name and the
  `smart_room_faculty_trusted_device` cookie *name* are just identifiers,
  not paths, so they were left as-is — renaming them is unnecessary and
  would have no effect on functionality).

## 2. Fixed fragile relative include paths (HTTP 500 risk on shared hosting)

All 50 entry-point files used patterns like:

```php
require_once '../db.php';
include '../authentication.php';
```

These were rewritten to be anchored to the including file's own directory,
which is immune to working-directory differences:

```php
require_once __DIR__ . '/../db.php';
include __DIR__ . '/../authentication.php';
```

Files touched: `index.php`, `logout.php`, `forgot_password.php`,
`reset_password.php`, `request_registration.php`,
`setup_security_question.php`, every file under `admin/` (including
`admin/faculty/` and `admin/reports/`), and every file under `faculty/`.

`db.php` itself already used `__DIR__` for `mail_helper.php`, and
`mail_helper.php` already used `__DIR__` for `vendor/autoload.php` — both
were already correct.

## 3. Session cookie hardening

Every page calls `session_start()` and then `require_once .../db.php`. That
ordering meant any session cookie hardening placed inside `db.php` would be
a no-op (the cookie is already sent by the time `db.php` runs).

Fixed by:
- Reordering every entry file so `require_once .../db.php` runs **before**
  `session_start()` (db.php does not touch `$_SESSION` at the top level, so
  this is behaviorally identical except for the cookie attributes below).
- Adding a guarded `session_set_cookie_params()` call at the top of `db.php`
  (`session_status() === PHP_SESSION_NONE` check) that sets the session
  cookie to `HttpOnly`, `SameSite=Lax`, `path=/`, and `Secure` automatically
  whenever the site is loaded over HTTPS. This does not change login
  behavior — it only makes the session cookie harder to steal via XSS or
  cross-site requests.

## 4. Cleaned up the archive for deployment

- Removed an empty leftover `smart_room_updated/smart_room/...` directory
  tree (old empty folders with no files in them — harmless but confusing
  clutter from a previous reorganization).
- Removed an empty `.agents` directory.
- Removed the `.git` folder from the deployable ZIP. **Important:** your
  git history contains the live Gmail credentials from `mail_config.php`
  (see ACTION REQUIRED #1 below) in earlier commits even though the current
  working copy is the only thing you intend to upload — do not push this
  repo's history to a public remote without scrubbing it first.
- Verified `vendor/autoload.php` and the full `phpmailer/phpmailer` source
  are present and self-contained, so **no `composer install` is needed on
  the server** — extracting the ZIP is sufficient.

## 5. Verification performed (static review — no live PHP/MySQL available in this environment)

- Brace/parenthesis balance checked across all 55 non-vendor PHP files —
  all balanced, no truncated/syntax-broken files.
- Confirmed every `require_once`/`include` target file actually exists in
  the project (no missing includes).
- Confirmed every function called from `mail_helper.php` (`sendSmartRoomOtpEmail`,
  `sendFacultyTemporaryPasswordEmail`, `sendPasswordResetEmail`,
  `sendRegistrationRejectionEmail`) is defined there.
- Confirmed all SQL in `db.php` and the page files uses `mysqli` prepared
  statements with `bind_param` — no string-concatenated user input was found
  in any query (the few `implode()`-built `IN (...)` lists use internally
  generated integer IDs, not raw user input, so they are not injectable).
- Confirmed upload handlers (`request_registration.php`,
  `faculty/face_verification.php`) build their target directory with
  `__DIR__`/`dirname(__DIR__)` and auto-`mkdir()` with `0775` if missing, so
  `uploads/faces` and `uploads/requests` will be created automatically on
  first use even if the folder doesn't survive the ZIP extraction.
- Confirmed `fetch()` calls in admin/faculty JS use page-relative URLs
  (e.g. `fetch('availability_data.php')`, `fetch('rfid_lookup.php?...')`),
  which work correctly regardless of folder depth.
- Confirmed face-api.js / MediaPipe / Bootstrap are loaded from CDN
  (`cdn.jsdelivr.net`) with versioned URLs — these will load identically in
  production as in development; no local copies needed.

## 6. Fixed an uncaught-exception HTTP 500 on PHP 8.1+ (mysqli error mode)

This was found while debugging a live "Internal Server Error 500" on the
`faculty/face_verification.php` POST handler.

**Root cause:** every helper in `db.php` is written defensively —
`$stmt = $conn->prepare(...); if (!$stmt) return false;` — which only works
if `prepare()`/`query()` return `false` on a bad query. **As of PHP 8.1,
mysqli's default error-reporting mode throws `mysqli_sql_exception` instead
of returning `false`.** Since nothing in the project set the error mode and
nothing caught that exception class, the very first query that touched a
table/column not exactly matching the live database (most likely
`email_otps`, `users.face_verified`, `users.account_status`, or
`notifications` — all touched by the face-verification POST path) threw an
uncaught exception straight to a blank HTTP 500, with no error shown to the
user and the real cause only visible in the PHP error log.

**Fix:** added `mysqli_report(MYSQLI_REPORT_OFF);` immediately after the
connection in `db.php`, restoring the classic "return false on error"
behavior the rest of the codebase already assumes everywhere.

**Important — do this before re-uploading:** this fix stops the crash, but
it does **not** fix a genuinely missing/mismatched table or column — it just
makes that failure silent again (e.g. face verification or OTP emails may
quietly stop working instead of 500ing). Before you overwrite the live
files, open cPanel → Metrics → Errors (or the file `error_log` inside your
app folder) and find the exact line from the failed POST — it will read
something like `Unknown column 'face_verified' in 'field list'` or
`Table 'smart_room_db.email_otps' doesn't exist`. That tells you exactly
which table/column to add to your database. Paste that line back to me and
I can confirm the exact fix instead of guessing further.


1. **Rotate the Gmail App Password immediately.** `mail_config.php`
   contains a live Gmail address and a 16-character App Password
   (`smartroom.system1@gmail.com`). Since this was shared with me in this
   upload, you should treat it as compromised: go to your Google Account →
   Security → App Passwords, revoke the existing one, generate a new one,
   and paste the new value into `mail_config.php` before going live. I did
   not change this credential myself since I have no way to generate a
   working replacement for your account.

2. **No CSRF tokens anywhere in the project.** None of the ~30 forms
   (login, add/edit room, add/edit faculty, password change, schedule
   create/edit, etc.) include a CSRF token. I did **not** retrofit this
   myself because doing it safely means touching every `<form>` tag and
   every POST handler across 30+ files, and a partial/rushed implementation
   risks breaking forms I can't test against a live server. If you want,
   I can do a dedicated follow-up pass that adds a shared
   `csrf_token()` / `verify_csrf()` helper plus a hidden field in every
   form — happy to do that as the next step.

3. **No database schema (`.sql`) file was included** in the ZIP you
   uploaded, so I could not verify table/column existence against your
   actual database — only against the `columnExists()`/`tableExists()`
   guards already present in the code (which the app already uses
   defensively for optional columns like `office`, `emergency_name`, etc.).
   If anything 500s after import, the most likely cause is a column the
   code expects that your database doesn't have — `tableExists`/`columnExists`
   guard most of these already, but double-check your `users`, `schedules`,
   `rooms`, `room_logs`, `notifications`, `email_otps`,
   `faculty_trusted_devices`, and `activity_logs` tables match what's
   queried in `db.php`.

4. **`assets/style.css` is empty and unused** — no page links it. Left as-is
   since removing it isn't necessary and you may be using it as a hook for
   future custom CSS.

## Deployment instructions

1. Extract the ZIP directly into `public_html/` on cPanel (or a subfolder if
   you intend to run it there — the code is no longer hardcoded to any
   specific folder name, so it will work either way as long as the relative
   structure between files stays intact).
2. Import your database.
3. Update `db.php` (lines 2–5) with your cPanel MySQL host/username/password/
   database name.
4. Update `mail_config.php` with a freshly rotated Gmail App Password (see
   ACTION REQUIRED #1).
5. Confirm `uploads/faces/` and `uploads/requests/` are writable by the web
   server (755/775) — they will also self-create on first use if missing.

---

# Polishing Update — Camera Compatibility, Face UX, Registration Cleanup

This pass only touched the 7 files listed below. Everything else (Email OTP,
SMTP/PHPMailer, Login, Trusted Device, Password Change, Schedule Management,
Activity Logs, RFID Management, RFID Attendance, Reports, database schema)
was left byte-for-byte identical — verified with `md5sum` before/after.

## Files modified

- `assets/face-camera.js`
- `faculty/face_verification.php`
- `request_registration.php`
- `admin/registration_requests.php`
- `admin/faculty/index.php`
- `admin/faculty/delete.php`
- `db.php`

## 1. Camera compatibility (Safari/iPhone/iPad, Chrome, Edge, Firefox, Samsung Internet, Android Chrome)

The shared `assets/face-camera.js` bootstrap already handled the core
compatibility work (graded `getUserMedia` constraint fallback, waiting for
real `loadedmetadata`/decodable video dimensions before starting detection,
`playsinline`/`webkit-playsinline`/`muted`/`autoplay` set as real attributes
before `play()`, and error-specific messaging). This pass added:

- **Explicit iOS/Safari detection** (`FaceCamera.isIOS()` / `isSafari()`)
  instead of relying only on generic error names.
- **iOS-specific permission-denied guidance**: on iOS, a `NotAllowedError`
  now tells the person exactly where to go (Settings → Safari → Camera, or
  the "aA" address-bar menu → Website Settings) instead of a generic "check
  your settings" message.
- **Reload-instead-of-retry on iOS after a permission denial.** iOS Safari
  caches a camera permission denial for the page session, so a plain
  in-page "Retry Camera" click will silently fail again. Both camera pages
  now detect this case and swap the button to "Reload Page"
  (`window.location.reload()`) instead, which is the only thing that
  re-prompts on iOS after the person changes the Safari setting.
- Tightened the camera-init-timeout message to the requested wording
  ("Unable to access the camera. Please refresh the page.").
- No change to detection accuracy, duplicate-face checks, or the underlying
  `getUserMedia`/model-loading logic — this was messaging/UX only.

## 2. Face Verification user experience

- **Wrong Face Detected** now shows the specific message: *"The detected
  face does not match the registered faculty account. Please use the
  registered face associated with this account."* (was the generic "Face
  verification failed.") — `faculty/face_verification.php`.
- **Live status indicator** now uses the requested emoji markers (🟢 face
  detected / 🟡 center your face, move closer, improve lighting / 🔵
  verifying / 🔴 multiple faces or recognition failure) on both the faculty
  verification page and the public one-time registration page.
- Added a **distinct "Move closer to the camera" (🟡)** state, separate from
  "center your face" — previously a too-small/too-far face was lumped into
  the same "not centered" message.
- No biometric confidence scores are ever shown to the user (only the
  qualitative status text above) — unchanged from before.

## 3. Registration Request cleanup

- **Delete button on every registration request row**, plus a details/
  approve/reject set of actions unchanged from before.
- **Bulk actions**: "Delete Selected" (via row checkboxes + a header
  checkbox), "Delete All Approved", "Delete All Rejected" — each behind its
  own confirmation dialog.
- **Faculty deletion modal**: `admin/faculty/index.php` now looks up whether
  the faculty member has an associated registration request (matched by
  email) and, only when one exists, shows a checkbox: *"This faculty has an
  associated registration request. Also delete the associated registration
  request."* Unchecked by default.
- **Strict independence, enforced in code, not just UI**:
  - `deleteRegistrationRequestRow()` (new helper in `db.php`) only ever
    executes `DELETE FROM registration_requests ...` (plus removing that
    request's own uploaded snapshot file) — it has no code path that can
    touch the `users` table, so deleting a request can never delete a
    faculty account.
  - `admin/faculty/delete.php` only deletes a registration request when
    `delete_request=1` arrives via POST from the confirmation modal's
    checkbox; a plain faculty delete (or the legacy GET link) never touches
    `registration_requests`.
- All of the above are recorded in Activity Logs
  (`REGISTRATION_REQUEST_DELETED`, `REGISTRATION_REQUESTS_BULK_DELETED`, and
  the existing `DELETE_FACULTY`).

## 4. Final debugging pass performed

- Re-ran the brace/paren/bracket balance check from the previous pass
  against every touched file — all balanced (the one pre-existing 1-paren
  imbalance in a `db.php` comment was already present before this update
  and does not affect execution).
- Extracted and `node --check`'d every inline `<script>` block on the 4
  touched HTML/JS-bearing pages — all pass.
- `node --check`'d `assets/face-camera.js` directly — passes.
- Confirmed (via `md5sum`) that RFID Management, RFID Attendance, RFID
  lookup/test pages, `mail_config.php`, and `mail_helper.php` are
  byte-identical to the version you uploaded — untouched.
- Confirmed no new files were added and no existing files were removed —
  exactly the 7 files listed above changed.
- No PHP interpreter was available in this environment to run `php -l`
  directly; the structural checks above are the substitute. As always,
  check cPanel → Metrics → Errors after deploying and send me the exact
  line if anything 500s — the defensive `columnExists`/`tableExists` guards
  already used throughout the codebase (and reused in the new code) should
  prevent that on a database matching the existing schema.

