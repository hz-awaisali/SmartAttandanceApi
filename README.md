# SmartAttendance API

A backend-only **Laravel 12** REST API for a university management system, built
around two capabilities:

1. **Smart Attendance** — students are marked present automatically when
   their phone detects a classroom's **BLE iBeacon** while inside a **GPS
   geofence** around campus. No manual roll call, no WiFi MAC-address
   guessing — a physical beacon + a location check + a signal-strength
   check, cross-validated server-side.
2. **Digital Application Tracking** — a **dynamic, admin-configurable
   workflow engine** for routing student/staff applications (transcript
   requests, leave applications, transport passes, …) through a chain of
   department officials who can review, approve, reject, or forward them
   with remarks — without hard-coding a single workflow in PHP.

There is **no frontend in this repository**. The client is a Flutter app
(**CampusOS**, developed alongside this API) for students, teachers, HODs,
and admins; this repo is purely the JSON API both talk to.

---

## Table of contents

- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Getting started](#getting-started)
- [Testing](#testing)
- [Module 1 — Smart Attendance](#module-1--smart-attendance)
- [Module 2 — Digital Application Tracking](#module-2--digital-application-tracking)
- [Non-teaching office holders — the `staff` role](#non-teaching-office-holders--the-staff-role)
- [Domain model](#domain-model)
- [API surface](#api-surface)
- [Project structure](#project-structure)
- [Deployment](#deployment)
- [Further reading](#further-reading)

---

## Tech stack

| Layer | Technology |
|---|---|
| Language / framework | PHP 8.2, **Laravel 12** |
| API style | Pure JSON REST — no Blade views, no Livewire/Vue/React, no JS build pipeline |
| Auth | **Laravel Sanctum** (Bearer personal access tokens) |
| Database (local/dev) | SQLite |
| Database (production) | MySQL |
| Session / Cache / Queue driver | `database` for all three (queues are configured but nothing is actually dispatched — everything runs synchronously) |
| Authorization | Route-level `role:` middleware (coarse) + Laravel Policies (fine-grained, per-object) |
| Testing | PHPUnit, run against a real MySQL `test` database (see [Testing](#testing)) |
| Dev tooling | Laravel Pint, Sail, Pail, Mockery, Collision, Faker |
| Deployment | Docker (`php:8.3-cli`, Laravel's built-in server — no nginx/php-fpm) via Coolify |
| Client | Flutter app "CampusOS" (separate repository) |

---

## Architecture

Every request flows through the same shape, in both modules:

```
Route (role: / auth:sanctum middleware)
  → FormRequest         (validates input)
  → Controller           (thin — validate, delegate, return a Resource)
  → Service               (all real business logic lives here)
  → Policy                 (object-level authorization, where relevant)
  → Eloquent Model(s)
  → API Resource            (shapes the JSON response)
```

- **Controllers are thin.** They never contain business rules — they call a
  service method and wrap the result in `$this->ok(...)` /
  `$this->fail(...)`, which both return the app-wide envelope
  `{"message": ..., "data": ...}`.
- **Domain-rule violations throw `App\Exceptions\BusinessException`**
  (a message + HTTP status). It self-renders as JSON, so controllers need
  no try/catch boilerplate.
- **Two-layer authorization**: `role:` middleware aliases
  (`role:teacher,hod`, etc., resolved by `App\Http\Middleware\EnsureUserHasRole`)
  gate entire routes by coarse role; Laravel **Policies** handle anything
  role alone can't express (e.g. "this teacher can only block students in
  batches they actually teach").
- **No queues, no scheduler.** Despite queue tables existing, nothing is
  ever dispatched — notifications are synchronous DB writes, and a class
  session must be manually ended by a teacher (no auto-expiry job).

### A self-contained second module

The Application Tracking module is deliberately isolated in its own
namespace rather than mixed into the flat `app/` structure the core
attendance system uses:

```
app/
├── Http/, Models/, Services/, Policies/, Exceptions/   ← core attendance system
└── Modules/
    └── ApplicationTracking/                             ← entirely self-contained
        ├── ApplicationTrackingServiceProvider.php        ← the ONLY line touching an existing file
        ├── Models/  Services/  Policies/  Http/
        ├── Database/Migrations/  Database/Seeders/
        └── routes.php
```

`ApplicationTrackingServiceProvider` (registered in `bootstrap/providers.php`)
mounts its own `routes.php` under the same `api` middleware group and prefix
`bootstrap/app.php` already applies to `routes/api.php`, and registers its
own migrations path via `loadMigrationsFrom()`. **`routes/api.php` itself is
never touched.** The module reuses the core system's shared plumbing
(`NotificationService`, `AuditLogService`, `BusinessException`, the
`public` storage disk, the `role` middleware alias) rather than duplicating
any of it — see [Module 2](#module-2--digital-application-tracking) for why
it's built this way.

---

## Getting started

### Requirements

- PHP 8.2+ with `pdo_sqlite` (dev) and `pdo_mysql` (testing) extensions
- Composer
- SQLite (dev database) and MySQL (test database) — a local stack like
  XAMPP/Laragon/Herd works fine for both

### Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` defaults to SQLite for local development:

```env
DB_CONNECTION=sqlite
```

Create the SQLite file and migrate:

```bash
touch database/database.sqlite   # Windows: New-Item database/database.sqlite
php artisan migrate
php artisan db:seed              # realistic demo data — see below
```

`php artisan db:seed` only seeds the **core attendance system** (see
`database/seeders/DatabaseSeeder.php`: departments, programs, batches,
teachers, students, rooms with beacon config, and one demo class session).
The Application Tracking module ships its **own seeder**, run separately by
design (see [Module isolation](#a-self-contained-second-module) above):

```bash
php artisan db:seed --class="App\Modules\ApplicationTracking\Database\Seeders\ApplicationTrackingSeeder"
```

That seeds 3 official accounts (Examination/Transport/IT Officer, password
`password`), their `Office` records, and 3 illustrative application
categories — see [Module 2](#module-2--digital-application-tracking).

### Running it

```bash
php artisan serve
```

By default the API is now live at `http://127.0.0.1:8000/api`. Every seeded
account (both seeders) uses password **`password`**.

### Config that changes runtime behavior

`config/attendance.php`, via `.env`:

```env
ATTENDANCE_CAMPUS_LAT=
ATTENDANCE_CAMPUS_LNG=
ATTENDANCE_GEOFENCE_RADIUS=150
```

If `ATTENDANCE_CAMPUS_LAT`/`LNG` are left blank, the GPS geofence check in
`AttendanceService::mark()` is a **silent no-op** — easy way to accidentally
ship a build with no location enforcement, worth knowing when debugging
"attendance marks from anywhere."

---

## Testing

```bash
php artisan test
php artisan test --filter=test_full_transcript_request_happy_path
php artisan test tests/Feature/AttendanceReportTest.php
```

Tests run against **MySQL**, not the SQLite used for local dev —
`phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=test`. A local MySQL
server with an empty `test` database must exist before running the suite.
Feature tests use `RefreshDatabase`, so the schema (both the core migrations
*and* the Application Tracking module's, since its service provider is
always registered) is rebuilt automatically — no manual seeding needed for
tests, each one creates its own fixtures.

Coverage today: `AttendanceReportTest`, `PasswordResetTest`,
`ApplicationTrackingTest` (submit → approve → advance → approve; reject →
resubmit → re-enters the same step; direct-to-officer with no HOD step;
403 for the wrong office; a workflow step narrowed to one specific office
member — targeted user can act, other office holders cannot and don't see
it in their queue; duplicate-active-application blocked; cancel;
unstaffed-office guard), `StaffAccountTest` (admin creates a staff account
with a required `admin_department_id`; a staff account holds an Office and
acts on an assigned application; the staff dashboard shows identity +
pending queue; every attendance-module route 403s for staff; `GET
/sessions` returns empty for staff; staff password reset via
`employee_no`), and `AdminDepartmentTest` (the four baseline rows exist
after migrating; admin CRUD; non-admin blocked).

---

## Module 1 — Smart Attendance

### The real-world flow

1. Each classroom has an ESP32-S3 BLE beacon glued to the wall, broadcasting
   a passive **iBeacon** advertisement: a fixed **UUID** + **Major**
   (= room number), at low TX power (~20–30m range).
2. A teacher starts a class session: `POST /api/sessions/{timetable}/start`.
   Every student in that batch gets an `absent` attendance row pre-created.
3. The student's phone: confirms it's inside the campus GPS geofence →
   passively scans BLE → reads `{uuid, major, rssi}` from the classroom
   beacon → submits `POST /api/attendance/mark`.
4. The API validates GPS distance, RSSI strength, and beacon identity (in
   that order), then flips the student's row to `present`.
5. Any failed/suspicious submission is logged to `suspicious_attempts` —
   never silently dropped — for anti-cheating audit.
6. The teacher ends the session: `POST /api/sessions/{session}/end`. Any
   still-absent students get notified; the teacher gets a present/absent
   summary.

### Validation order (`AttendanceService::mark()`)

First failure wins, throwing `BusinessException`:

1. Session must be `active`.
2. Student must not be `is_blocked`.
3. The session's timetable batch must match the student's batch.
4. An attendance row must already exist and not already be `present`.
5. **GPS geofence** — haversine distance ≤ `geofence_radius_meters`.
6. **RSSI threshold** — signal strength ≥ the room's threshold (or the
   `-75` dBm default) — rejects "too far away."
7. **Beacon identity** — `detected_uuid`/`detected_major` must exactly
   match the room's configured beacon.

### Why BLE + GPS, not WiFi or rotating tokens

Two earlier designs were tried and abandoned (see `project_handoff.md` for
the full history): **WiFi SSID/MAC corroboration** (too easy to spoof) and a
**rotating HMAC token** broadcast by the beacon (impossible to keep in sync
— the ESP32 beacons have no RTC or WiFi). The current design (static
UUID/Major + GPS geofence + RSSI strength) accepts a documented residual
risk — a captured static UUID/Major could theoretically be replayed by a
modified app that also fakes GPS/RSSI — as good-enough for the threat model.

---

## Module 2 — Digital Application Tracking

A generic **approval-workflow engine**: any authenticated user submits an
application (Transcript Request, Leave Application, Transport Pass, ...)
that routes through a chain of department officials who review, approve,
reject, or forward it with remarks — where the officials, the form fields,
and the approval path are **admin-configured data**, not hard-coded PHP.

### Core concepts

| Concept | What it is |
|---|---|
| **Office** | A dynamic official position (Examination Officer, Transport Officer, IT Officer, ...) — decoupled entirely from `users.role`. Admin creates one and assigns users to it via `office_user`; reassigning staff is a data change, not a code change. |
| **WorkflowTemplate / WorkflowStep** | A named, ordered chain of steps. Each step names its approver — either a specific **Office**, or the special `applicant_department_hod` type, which resolves **dynamically** off the existing `Department.hod_teacher_id` link (no separate "HOD office" to keep in sync). Steps are wired into a straight chain automatically from submission order — approving a step advances to the next one, or finalizes the application if there isn't one. |
| **ApplicationCategory** | An admin-defined application *type*: a name, a JSON `form_schema` (the dynamic form fields), a linked `WorkflowTemplate`, and optional `applicant_roles` restricting who may submit it. |
| **Application** | A submitted instance: locked `form_data`, a `current_step_id`, and a `status` (`pending`, `returned_for_revision`, `approved`, `rejected`, `cancelled`). |
| **ApplicationAction** | The full audit timeline — one row per submit / approve / reject / forward / comment / resubmit / cancel, all visible to the applicant. |
| **ApplicationAttachment** | Files, either tied to a specific `file`-type form field or general-purpose, on the `public` disk (same convention as avatar uploads). |

### Why a linear chain, not a full BPMN graph

Every real example given for this system — "Transcript Request needs HOD
approval, then goes to the Examination Officer" vs. "Transport Pass goes
straight to the Transport Officer, no HOD needed" — is expressible as a
**straight ordered chain with a reject-branch**. Building a general
conditional/branching engine (routing differently based on submitted field
values) would be real added complexity for both the engine and any future
admin UI, with no example requiring it yet.

### Dynamic form definition

`application_categories.form_schema` is a JSON array the admin defines per
category:

```json
[
  {"key": "reason", "label": "Reason for request", "type": "textarea", "required": true},
  {"key": "semester", "label": "Semester", "type": "number", "required": true},
  {"key": "medical_certificate", "label": "Medical Certificate", "type": "file", "required": true}
]
```

`DynamicFormValidator` turns this into Laravel validation rules at
submit/resubmit time (`text`, `textarea`, `number`, `date`, `select`,
`file` — file fields get a 10MB / pdf-doc-docx-jpg-jpeg-png-webp check).
No form-builder UI is needed in this API-only repo — a client renders the
schema into an actual form.

### The engine's rules, in one place

- **`form_data` is locked at submission.** The only way to change it is the
  formal reject → resubmit loop below — one clear audit trail, not two
  different "edit" code paths.
- **One active application per category at a time**, per applicant, unless
  a category explicitly opts in to `allow_multiple_active`.
- **Rejection can terminate or return for revision**, configured per step
  (`on_reject_action`). A returned application stays parked at the *same*
  step — the applicant edits and resubmits, and it goes straight back to
  whoever asked for the changes, not the start of the chain.
- **An unstaffed office blocks cleanly.** Submitting into, or advancing
  into, a step whose office has zero assigned users throws a
  `BusinessException` naming the office — an application can never
  silently strand itself with nobody able to act on it, and an approval
  action that can't land anywhere fails without being lost.
- **`forward` is a real capability, not just a notification.** A step can
  opt in to `allow_forward`; forwarding pings that office and — for *this
  specific application only* — grants it the same acting authority as the
  step's own configured office, without altering the reusable workflow
  definition itself.
- **Finalized applications are permanent.** No admin override reopens an
  `approved`/`rejected`/`cancelled` application — consistent with the
  append-only audit philosophy the rest of the app already follows
  (`AuditLog`, `SuspiciousAttempt`).
- **Pagination is real here.** Unlike the rest of the API (see the note in
  [API surface](#api-surface) below), this module's list endpoints return
  proper `{data, meta}` pagination — an approvals queue genuinely needs
  page/total counts, and there were no existing client screens to stay
  byte-for-byte consistent with.

### Walkthrough: Transcript Request end-to-end

```bash
# 1. Student sees what they're allowed to submit
GET /api/application-categories
# → Transcript Request (student-only, 2-step: HOD → Examination Officer)

# 2. Student submits
POST /api/applications
  application_category_id=1
  form_data[reason]=Applying for a job, need official transcript
  form_data[semester]=4
# → status: pending, current_step: "Applicant's Department HOD"

# 3. The applicant's own department HOD approves — resolved live,
#    no office assignment needed for this step
POST /api/applications/1/act   { "action": "approve" }
# → status: pending, current_step: "Examination Officer"

# 4. Whoever holds the Examination Officer office approves
POST /api/applications/1/act   { "action": "approve", "remarks": "Transcript issued" }
# → status: approved, resolved_at set

# Student's GET /api/notifications now shows "Application Approved"
```

### Seeded demo data (`ApplicationTrackingSeeder`)

Three categories, deliberately chosen to demonstrate every path shape the
engine supports:

| Category | Path | Demonstrates |
|---|---|---|
| **Transcript Request** | student's department HOD → Examination Officer | multi-step, HOD-gated |
| **Leave Application** | student's department HOD only | HOD-only, direct final approval |
| **Transport Pass** | Transport Officer only | direct-to-officer, **no HOD step at all** |

Plus 3 official accounts (`examination.officer@university.edu`,
`transport.officer@university.edu`, `it.officer@university.edu`, all
password `password`) already assigned to their respective `Office`.

### One thing to know if you extend it

`applications` snapshots `workflow_template_id` at submission, but the
step chain itself is looked up live via `current_step_id`. To keep an
in-flight application from having its steps pulled out from under it,
`Admin/WorkflowTemplateController` refuses to edit/replace the step chain
on a template that still has non-terminal applications referencing it.

---

## Non-teaching office holders — the `staff` role

An `Office` (Examination Officer, Transport Officer, ...) can be held by
any `User` — that was always decoupled from `role`, so a teacher who also
holds an office got both modules automatically, no special-casing needed.
What was missing was a way to create an account for an office holder who
**isn't** a teacher at all (a Registrar, an IT Officer). The fix is a
fifth, coarse `role` value: **`staff`** — a plain account with **zero
attendance-module access**, only Application Tracking.

- **Admin-only CRUD**: `GET/POST /staff`, `GET/PUT /staff/{id}` — same
  shape as the `Teacher` admin endpoints, minus the HOD-scoped index
  (staff are university-wide, not department-bound).
- **New `staff` table**: mirrors `Teacher` (`employee_no`, `designation`,
  `phone`, nullable `department_id`) — needed so password reset can verify
  staff the same `email + employee_no` way it already verifies teachers.
- **Attendance exclusion is enforced, not just assumed**: `staff` simply
  never appears in any existing `role:` middleware allowlist, so every
  attendance-module route already 403s it by construction. The one route
  that needed an explicit fix was `GET /sessions` — it has no `role:`
  middleware at all (any authenticated user can call it) and used to
  silently fall through to "show everything" for any role it didn't
  recognize; it now explicitly shows nothing unless the caller is
  `teacher`/`student`/`hod`/`admin`.
- **Full detail and a Flutter implementation guide**: see
  [STAFF_ACCOUNTS.md](STAFF_ACCOUNTS.md), including the role↔module access
  matrix and why a "teacher who also holds an office" is a different case
  from a `staff` account.

---

## Domain model

**Core attendance system:**

```
Department ──< Program ──< ProgramCourse
     │              │
     │              └──< Batch ──< Student
     └──< Teacher

Timetable (Batch + ProgramCourse + Teacher + Room + Day + TimeSlot)
     └──< ClassSession
              ├──< Attendance          (one row per student in the batch)
              └──< SuspiciousAttempt   (rejected submissions, audit trail)
```

- **User** — `role` enum (`admin`/`hod`/`teacher`/`student`/`staff`),
  Sanctum `HasApiTokens`. Widened once, deliberately, for `staff` (see
  [above](#non-teaching-office-holders--the-staff-role)) — a coarse
  access-tier addition, not a place to add specific job titles (those stay
  data, via `Office`).
- **Teacher** — `is_hod` is *computed* (true iff `Department.hod_teacher_id`
  points at this teacher), never a stored flag.
- **Staff** — non-teaching office holders (Examination Officer, Registrar,
  ...). Same shape as `Teacher` minus any teaching-specific concept, but
  belongs to an **`AdminDepartment`** — a completely separate hierarchy
  from academic `Department` (Examination Department, IT Department,
  Registrar Office, Transport Department, ...). See
  [Non-teaching office holders](#non-teaching-office-holders--the-staff-role)
  above and [STAFF_ACCOUNTS.md](STAFF_ACCOUNTS.md) for why these are two
  distinct hierarchies rather than one.
- **AdminDepartment** — the administrative counterpart to `Department`.
  `admin_department_id` on `Staff` and on the Application Tracking
  module's `Office` both point here, never at academic `Department`.
- **Room** — `beacon_major` + `beacon_uuid` (the iBeacon identity) +
  `rssi_threshold`.
- **Notification** — a simple directly-queryable table (not Laravel's
  polymorphic notifications), `type` is a DB-level enum shared by both
  modules (see below).

**Digital Application Tracking** (8 additional tables, all in the module's
own migrations, zero changes to any table above):

```
Office ──< office_user >── User
AdminDepartment ──< Office (required — every Office belongs to an admin department)

WorkflowTemplate ──< WorkflowStep (self-referencing "next step on approval")
WorkflowStep ──> Office (optionally narrowed to one specific User within it)

ApplicationCategory ──> WorkflowTemplate
Application ──> ApplicationCategory, User (applicant), WorkflowStep (current)
     ├──< ApplicationAction   (the approval timeline)
     └──< ApplicationAttachment
```

**One cross-module integration point worth knowing about**: `notifications.type`
is a native DB enum. Rather than build a second notification system, the
Application Tracking module reuses `NotificationService`/the shared
`notifications` table, and one of its own migrations *widens* that enum
(`application_submitted`, `application_approved`, `application_rejected`,
`application_forwarded`, `application_commented`, `application_resubmitted`,
`application_cancelled`) — additively, every original attendance-related
value stays valid.

---

## API surface

All routes are prefixed `/api`. Auth is **Sanctum Bearer tokens**
(`Authorization: Bearer <token>` from `/auth/login` or `/auth/register`).
Every response follows `{"message": ..., "data": ...}`; validation failures
are 422 with an `errors` map; domain-rule failures use
`BusinessException`'s custom status; role mismatches are 403.

> **Pagination gotcha (core API only):** list endpoints in the *core*
> attendance system call `ok(Resource::collection($paginator))`, which
> embeds the collection inside another array — this bypasses Laravel's
> special paginated-response wrapping, so `data` comes back as a **flat
> array with no page/total metadata**. The **Application Tracking module's**
> list endpoints deliberately fix this and return real `{data, meta}`
> pagination instead (see [Module 2](#module-2--digital-application-tracking)).

<details>
<summary><strong>Core attendance system routes</strong></summary>

| Area | Routes |
|---|---|
| Auth | `POST auth/register`, `POST auth/login`, `POST auth/forgot-password`, `POST auth/reset-password`, `POST auth/logout`, `GET auth/user` |
| Public lookups | `GET departments`, `GET programs`, `GET batches` |
| Student | `GET students/today-classes`, `GET students/schedule`, `GET students/attendance-history`, `POST attendance/mark`, `GET dashboard/student` |
| Teacher | `POST students/{student}/block`, `POST students/{student}/unblock`, `GET dashboard/teacher` |
| Teacher + HOD | `POST sessions/{timetable}/start`, `POST sessions/{session}/end`, `GET sessions/{session}/attendance`, `GET teacher/schedule` |
| Reporting | `GET attendance/report` (scope depends on role: teacher → own classes, HOD → own department, admin → global) |
| Admin + HOD (read) | `GET teachers`, `GET students`, `GET timetables` |
| Admin CRUD | `departments`, `programs`, `program-courses`, `batches`, `rooms`, `time-slots`, `teachers`, `students`, `timetables`, `staff`, `admin-departments`, `GET dashboard/admin` |
| HOD | `GET dashboard/hod` |
| Profile (any role) | `GET/PUT profile`, `PUT profile/password`, `POST/DELETE profile/avatar`, `DELETE profile` |
| Shared | `GET sessions`, `GET sessions/{session}`, `GET notifications`, `PUT notifications/{id}/read`, `DELETE notifications/{id}` |

</details>

<details>
<summary><strong>Application Tracking module routes</strong></summary>

| Area | Routes |
|---|---|
| Any authenticated user | `GET application-categories` (role-filtered), `POST applications`, `GET applications` (mine, or `?assigned=1` for officials — supports `?status=&category_id=&from=&to=&sort=`), `GET applications/{id}`, `POST applications/{id}/act`, `POST applications/{id}/resubmit`, `POST applications/{id}/cancel` |
| Staff only | `GET dashboard/staff` (identity + pending-approvals queue) |
| Admin only | `GET applications/dashboard` (counts by status, avg turnaround, pending-per-office), full CRUD on `offices` (each requiring an `admin_department_id`), `workflow-templates` (+ steps, optionally narrowed to one specific office member via `approver_user_id`), and mutations on `application-categories` |

</details>

---

## Project structure

```
app/
├── Console/, Exceptions/, Providers/
├── Http/
│   ├── Controllers/          Admin/*, Auth/, Student/, Teacher/, thin — validate + delegate
│   ├── Middleware/            EnsureUserHasRole
│   ├── Requests/               one FormRequest per write endpoint
│   └── Resources/               one API Resource per model
├── Models/                       Eloquent models — core attendance domain
├── Policies/                      ClassSessionPolicy, NotificationPolicy, StudentPolicy, TimetablePolicy
├── Services/                       AttendanceService, SessionService, DashboardService, ... — all real logic
└── Modules/
    └── ApplicationTracking/        self-contained — see "A self-contained second module" above

database/
├── migrations/                    core attendance system
├── seeders/DatabaseSeeder.php      core demo data
└── (module migrations/seeders live inside app/Modules/ApplicationTracking/Database/)

routes/
├── api.php                        core attendance system (module routes are NOT here — see above)
└── web.php                        health check only

tests/Feature/                     AttendanceReportTest, PasswordResetTest, ApplicationTrackingTest, StaffAccountTest, AdminDepartmentTest
```

---

## Deployment

Docker (`php:8.3-cli`, no nginx/php-fpm — Laravel's built-in server
directly), deployed via **Coolify**. The entrypoint runs
`php artisan migrate --force` then
`php artisan serve --host=0.0.0.0 --port=8000`. The Application Tracking
module's own seeder is **not** run automatically in the container — run it
manually against production if you want the demo Offices/categories there
too.

---

## Further reading

- **`STAFF_ACCOUNTS.md`** — the `staff` role in full detail: the
  role↔module access matrix, every new endpoint, and a Flutter
  implementation guide.
- **`APPLICATION_TRACKING.md`** — the Digital Application Tracking
  module's full API reference and a Flutter implementation guide (concepts,
  every endpoint, dynamic form rendering, suggested Dart models/screens).
- **`PROJECT_CONTEXT.md`** — a living, detailed reference on the core
  attendance system's domain model, design history, and known gaps.
- **`project_handoff.md`** — the full hardware/firmware story (ESP32 beacon
  design, decision log, what's left to build) — the richest source of
  project history, including why WiFi and rotating-token designs were
  abandoned.
- **`CLAUDE.md`** — repo conventions for AI coding agents working in this
  codebase (commands, architecture, gotchas).
- `project_api_spec.json`, `hod.json`, `teacher.json` (repo root) —
  **stale**, describe the old WiFi-MAC design predating BLE+GPS. Treat as
  historical only.
