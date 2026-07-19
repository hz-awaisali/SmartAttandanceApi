# Digital Application Tracking — Module Guide & API Reference

> Audience: whoever builds the Flutter screens for this module (CampusOS).
> The attendance module is already fully built on both ends — this document
> covers the **second, newly-built module only**: a dynamic, admin-
> configurable approval-workflow engine for student/staff applications
> (Transcript Request, Leave Application, Transport Pass, ...).
>
> Base URL: `http://sums.144.91.122.229.sslip.io/api` (same host/token/
> `Authorization: Bearer <token>` auth as the rest of the API — nothing new
> to set up on the client's auth layer).

---

## Table of contents

1. [What this module is, in plain terms](#1-what-this-module-is-in-plain-terms)
2. [Core concepts / glossary](#2-core-concepts--glossary)
3. [Conventions you need to know before writing the client](#3-conventions-you-need-to-know-before-writing-the-client)
4. [Application status lifecycle](#4-application-status-lifecycle)
5. [Dynamic forms — how to render `form_schema`](#5-dynamic-forms--how-to-render-form_schema)
6. [Full API reference](#6-full-api-reference)
7. [Suggested Dart models](#7-suggested-dart-models)
8. [Suggested screens per role](#8-suggested-screens-per-role)
9. [Notifications](#9-notifications)
10. [Demo data / accounts to test against](#10-demo-data--accounts-to-test-against)
11. [Known gaps — nothing built for these yet](#11-known-gaps--nothing-built-for-these-yet)

---

## 1. What this module is, in plain terms

Any logged-in user (student, teacher, HOD — role depends on the category)
can submit an **application** of some admin-defined **category** (e.g.
"Transcript Request"). That application then moves through a chain of
**officials** — some fixed office (Examination Officer, Transport Officer,
...), or the applicant's own department HOD — who each **approve, reject,
forward, or comment**. The chain, the officials, and even the form fields
are **data**, configured by an admin through the API — not hard-coded per
category. The client doesn't need to know "Transcript Request has 2 steps"
anywhere; it just calls `POST /applications` and follows whatever
`current_step` comes back.

---

## 2. Core concepts / glossary

| Term | Meaning |
|---|---|
| **AdminDepartment** | The administrative counterpart to an academic Department (Examination Department, IT Department, Registrar Office, Transport Department, ...). Every `Office` belongs to one. Fully separate table/ID space from academic `Department` — see [STAFF_ACCOUNTS.md](STAFF_ACCOUNTS.md). |
| **Office** | A named official position (e.g. "Examination Officer"), belonging to an **AdminDepartment**. Admin-created, admin-assigns users to it. Completely separate from a user's `role` (admin/hod/teacher/student) — an Office is *who currently holds a desk*, not what kind of account they have. |
| **WorkflowTemplate** | A named, ordered chain of **steps**. |
| **WorkflowStep** | One stop in the chain. Its approver is either a specific **Office** (optionally narrowed to *one specific member* of that office — see §6.9), or the special value `applicant_department_hod` (resolved automatically — whoever is HOD of the *applicant's own* academic department, no office needed). Approving a step moves to the next one, or — if there is no next step — finalizes the application. |
| **ApplicationCategory** | An application "type" a user can pick from: a name, a dynamic `form_schema` (see §5), a linked `WorkflowTemplate`, and optionally a restriction on who may submit it (`applicant_roles`). |
| **Application** | One submitted instance: locked form answers, a current step, a status. |
| **ApplicationAction** | One timeline entry — submit / approve / reject / forward / comment / resubmit / cancel. This *is* the "tracking" — render it as a vertical timeline in the detail screen. |
| **ApplicationAttachment** | A file, either tied to a specific `file`-type form field or general-purpose. |

---

## 3. Conventions you need to know before writing the client

### 3.1 Response envelope — **pagination is different here**

Every response is `{"message": ..., "data": ...}`, same as the rest of the
API. **But**: the core attendance API's list endpoints return a flat array
with no page metadata (that's why `teacher_service.dart` / etc. have that
manual "keep requesting pages until one comes back short" `listAll()`
helper). **This module's list endpoints do not have that problem** — they
return real pagination:

```json
{
  "message": "Success",
  "data": [ /* up to 15 items */ ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 42 }
}
```

Read `meta.last_page` / `meta.total` directly. **Do not** reuse the
`listAll()` page-walking pattern for this module's services — it isn't
needed and would be pointless extra requests.

### 3.2 Submitting/resubmitting is `multipart/form-data`, with nested keys

Because file uploads and form answers travel together, `POST /applications`
and `POST /applications/{id}/resubmit` are multipart requests using
**bracket notation**, not a JSON string:

```
application_category_id: 1
form_data[reason]: Applying for a job, need official transcript
form_data[semester]: 4
attachments[medical_certificate]: <file>   ← only for `file`-type fields, keyed by the field's `key`
```

In Dio terms, that's a `FormData.fromMap({...})` with dotted/bracket keys,
the same general shape `ProfileService.updateAvatar()` already uses for the
avatar upload — just with more fields. `POST /applications/{id}/act` and
`POST /applications/{id}/cancel`, by contrast, are plain JSON (no files
involved).

### 3.3 Errors

Same shape as everywhere else in the app:
- `422` — validation errors, `{"message": ..., "errors": {"field": ["..."]}}`, or a plain `{"message": "..."}` for a domain-rule violation (e.g. submitting into an unstaffed office).
- `403` — Policy failure (wrong office, application already resolved, not your own application).
- `409` — conflict (duplicate active application in the same category, acting on an already-resolved application).
- `404` — application/category/office not found or not visible to you.

### 3.4 Who can act on an application right now?

**Don't try to compute this client-side.** Always trust
`data.current_step` from the application detail response, and gate the
approve/reject/forward/comment buttons on whether the logged-in user is
plausibly that step's holder (you'll know because they land in that
official's `GET /applications?assigned=1` queue — see §6.3). The server is
the source of truth and will 403 regardless.

---

## 4. Application status lifecycle

```
                 ┌─────────────────────────────┐
                 │                              ▼
  submit ──▶ pending ──approve──▶ (next step, still pending) ──approve──▶ approved [final]
                 │                                                         ▲
                 │reject (step says "return_to_applicant")                 │
                 ▼                                                        approve (last step)
     returned_for_revision ──resubmit──▶ pending (same step it left) ─────┘
                 │
                 │ (applicant can also just walk away)
                 ▼
             cancelled [final]

  pending ──reject (step says "terminate")──▶ rejected [final]
```

- **`approved` / `rejected` / `cancelled` are permanent** — no endpoint
  reopens them. If a mistake happens, submit a new application.
- **`returned_for_revision`** is the only non-final "paused" state — the
  applicant edits and calls `resubmit`, which puts it back at the *same*
  step that rejected it (not the start of the chain).
- Every category has its own `allow_multiple_active` flag (default off) —
  by default a user can only have **one active** (`pending` or
  `returned_for_revision`) application per category at a time.

---

## 5. Dynamic forms — how to render `form_schema`

`ApplicationCategory.form_schema` is a JSON array the admin defines. Each
entry:

```json
{
  "key": "semester",
  "label": "Semester",
  "type": "number",
  "required": true,
  "max": 5,
  "options": ["A", "B", "C"]
}
```

| `type` | Render as | Notes |
|---|---|---|
| `text` | single-line text field | |
| `textarea` | multi-line text field | |
| `number` | numeric input | `max` (if present) is a numeric ceiling |
| `date` | date picker | ISO date string in `form_data` |
| `select` | dropdown | choices in `options` |
| `file` | file/photo picker | goes in `attachments[key]`, **not** `form_data[key]`; allowed: pdf/doc/docx/jpg/jpeg/png/webp, 10MB max |

**Build one generic form-renderer widget that walks this array** — that's
the entire point of the module being "dynamic": a new application category
an admin creates tomorrow (with fields nobody wrote UI for) should render
correctly with zero app updates. Validate `required` client-side for UX,
but the server re-validates everything anyway (`DynamicFormValidator`), so
don't worry about being exhaustive — a 422 with a field-keyed `errors` map
comes back either way.

---

## 6. Full API reference

All routes below are already prefixed with the base URL + `/api`. Auth
header required on every one of them (`Authorization: Bearer <token>`).

### 6.1 Browse categories

```
GET /application-categories
```
Any authenticated user. Returns only categories that are `is_active` **and**
match the caller's role against `applicant_roles` (null = open to
everyone). Admins see every category, including inactive ones. Paginated
(§3.1).

```json
{
  "message": "Success",
  "data": [
    {
      "id": 1,
      "name": "Transcript Request",
      "description": "Request an official academic transcript.",
      "form_schema": [ { "key": "reason", "label": "Reason for request", "type": "textarea", "required": true } ],
      "workflow_template_id": 1,
      "workflow_template": {
        "id": 1, "name": "Transcript Request Workflow", "is_active": true,
        "steps": [
          { "id": 1, "step_order": 1, "name": "Applicant Department HOD", "approver_type": "applicant_department_hod", "approver_office_id": null, "on_approve_next_step_id": 2, "on_reject_action": "return_to_applicant", "allow_forward": false },
          { "id": 2, "step_order": 2, "name": "Examination Officer", "approver_type": "office", "approver_office_id": 1, "on_approve_next_step_id": null, "on_reject_action": "terminate", "allow_forward": false }
        ]
      },
      "applicant_roles": ["student"],
      "allow_multiple_active": false,
      "is_active": true,
      "created_at": "2026-07-18T10:29:38.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

Use this to populate a "start a new application" picker. The `steps` array
is handy for a UI hint like "this goes to your HOD, then the Examination
Officer" but the client never needs to act on it directly.

### 6.2 Submit an application

```
POST /applications          (multipart/form-data — see §3.2)
```
Body: `application_category_id`, `form_data[...]`, `attachments[...]` (for
`file`-type fields only).

**201** on success:
```json
{
  "message": "Application submitted",
  "data": {
    "id": 1,
    "application_category_id": 1,
    "category": { "id": 1, "name": "Transcript Request" },
    "applicant_user_id": 7,
    "form_data": { "reason": "Applying for a scholarship, need official transcript", "semester": "4" },
    "current_step_id": 1,
    "current_step": { "id": 1, "name": "Applicant Department HOD" },
    "status": "pending",
    "submitted_at": "2026-07-18T10:30:22.000000Z",
    "resolved_at": null,
    "timeline": [
      { "id": 1, "workflow_step_id": 1, "actor_user_id": 7, "action": "submitted", "remarks": null, "forwarded_to_office_id": null, "form_data_snapshot": { "reason": "...", "semester": "4" }, "created_at": "2026-07-18T10:30:22.000000Z" }
    ],
    "created_at": "2026-07-18T10:30:22.000000Z"
  }
}
```

Failure modes worth designing UI for specifically (not just a generic error
toast):
- `409 "You already have an active application of this type."`
- `422 "The reviewing office for this application ("X") is not currently staffed. Please contact the administrator."`
- `403 "You are not eligible to submit this type of application."`

### 6.3 My applications / the queue assigned to me

```
GET /applications
GET /applications?assigned=1
GET /applications?status=pending&category_id=1&from=2026-07-01&to=2026-07-31&sort=-submitted_at
```
- No `assigned` param → **applications I submitted** (applicant view).
- `assigned=1` → **applications currently waiting on me** (official view —
  whichever office/HOD-link I currently hold). This is the natural data
  source for a "Pending Approvals" tab/badge for teacher/HOD/official
  accounts.
- `sort` accepts `submitted_at` or `status`, optionally prefixed with `-`
  for descending (default `-submitted_at`).

Paginated (§3.1), same item shape as §6.2's `data` but without `timeline`.

### 6.4 Application detail (full timeline)

```
GET /applications/{id}
```
Same shape as §6.2 but with `timeline` (every `ApplicationAction`, in
order) and `attachments` populated — this is the screen that renders the
approval history as a vertical timeline. Each timeline entry:

```json
{
  "id": 2,
  "workflow_step_id": 1,
  "step_name": "Applicant Department HOD",
  "actor_user_id": 2,
  "actor_name": "Dr. Ayesha Khan",
  "action": "approved",
  "remarks": "Looks good",
  "forwarded_to_office_id": null,
  "form_data_snapshot": null,
  "attachments": [],
  "created_at": "2026-07-18T10:30:42.000000Z"
}
```

`action` is one of: `submitted`, `approved`, `rejected`, `forwarded`,
`commented`, `resubmitted`, `cancelled` — **past tense**, describing what
happened (note: you *send* the present-tense verb `approve`/`reject`/
`forward`/`comment` to `/act`, see §6.5 — the timeline stores the resulting
event name). `form_data_snapshot` is only populated on `submitted`/
`resubmitted` rows, giving you the full edit history for free if you want
to show "what changed on resubmission."

403 if you're neither the applicant, nor ever touched this application, nor
currently hold its step's authority.

### 6.5 Act on an application (approve / reject / forward / comment)

```
POST /applications/{id}/act
Content-Type: application/json
{ "action": "approve", "remarks": "Looks good" }
```

| `action` | Effect |
|---|---|
| `approve` | Advances to the next step, or finalizes as `approved` if this was the last step. |
| `reject` | Finalizes as `rejected`, **or** sets `returned_for_revision` — depends on how the *current step* is configured (`on_reject_action`), not something the client chooses. |
| `forward` | Requires `forward_to_office_id` in the body. Only allowed if the current step has `allow_forward: true` (check `current_step` — that field isn't in the trimmed list responses, fetch the detail view to know). Pings that office and grants it acting authority on *this application*, without changing the reusable workflow. |
| `comment` | Adds a timeline entry with no state change — visible to the applicant too (there's no internal/external split). |

`remarks` is optional for all four but strongly recommended for
`reject`/`forward` — it's shown to the applicant. Returns the updated
`ApplicationResource` (§6.4 shape, with `timeline`). 403 if the caller
doesn't hold the current step; 409 if the application is already resolved.

### 6.6 Resubmit (after a reject-for-revision)

```
POST /applications/{id}/resubmit          (multipart/form-data)
form_data[...]                             ← the corrected answers
attachments[...]                           ← optional replacement/additional files
```
Only valid when `status === "returned_for_revision"` and you're the
applicant. Re-enters the same step that rejected it. 409 otherwise.

### 6.7 Cancel

```
POST /applications/{id}/cancel
```
No body. Only valid while `status` is `pending` or `returned_for_revision`,
and only by the applicant. Sets `status: "cancelled"`, permanent.

### 6.8 Admin — Offices

```
GET  /offices
POST /offices          { "name": "Transport Officer", "admin_department_id": 4, "user_ids": [12, 15] }
GET  /offices/{id}
PUT  /offices/{id}     { "name"?, "admin_department_id"?, "user_ids"? }
```
Admin-only. `admin_department_id` is **required** — every Office belongs to
an *administrative* department (Examination Department, IT Department,
Registrar Office, Transport Department, ...), a completely separate table
from the academic `departments` used by Teacher/Student — see
[STAFF_ACCOUNTS.md §1](STAFF_ACCOUNTS.md#1-the-problem-this-solves--two-kinds-of-departments)
for the full picture, and `GET /admin-departments` to list valid ids.
`user_ids` **replaces** the full set of holders (it's a sync, not an add) —
send the complete list every time, including existing holders you want to
keep.

### 6.9 Admin — Workflow templates

```
GET  /workflow-templates
POST /workflow-templates
{
  "name": "Transcript Request Workflow",
  "steps": [
    { "name": "Applicant Department HOD", "approver_type": "applicant_department_hod", "on_reject_action": "return_to_applicant" },
    { "name": "Examination Officer", "approver_type": "office", "approver_office_id": 1, "approver_user_id": null, "on_reject_action": "terminate", "allow_forward": true }
  ]
}
GET  /workflow-templates/{id}
PUT  /workflow-templates/{id}     (same shape; omit "steps" to only rename/toggle is_active)
```
Admin-only. **`steps` is a plain ordered array — the chain is wired
automatically** from array order (`step[0]` → `step[1]` → ... → final);
never send `on_approve_next_step_id` yourself. `approver_type` is either
`"office"` (then `approver_office_id` is required) or
`"applicant_department_hod"` (then no office needed at all).
Updating `steps` on a template that has any non-terminal (`pending`/
`returned_for_revision`) applications still referencing it is blocked with
a `409` — finish or cancel those first, or create a new template instead.

#### Specific officer targeting

An `office`-type step can optionally set `approver_user_id` to narrow it
down from "any current holder of this Office" to **one specific person**.
Use this when broadcasting to every office member isn't wanted (e.g. a
routing rule like "result verification always goes to this one specific
Controller, not the whole Examination Officer desk").

- The chosen `approver_user_id` **must be a current member of
  `approver_office_id`** — the server validates this and returns `422` with
  `"The chosen approver for step "X" is not a member of the selected
  office."` if not. Office stays the organizational anchor; this field only
  ever narrows it, never replaces it.
- When set: only that person sees the application in
  `GET /applications?assigned=1`, only they can `act` on it (everyone else
  who holds the same Office gets `403`), and notifications only go to them
  — not the whole office.
- When left `null` (the default, and everything built before this): behaves
  exactly as before — every office holder is notified, sees it in their
  queue, and can act (whoever acts first resolves it).
- `WorkflowStepResource` reflects this back as `approver_user_id` +
  `approver_user` (`{id, name, email}`) alongside the existing `office`
  field, so an admin-side workflow builder can show "→ targets: Amina
  Controller" instead of just "→ Examination Officer desk."
- This is **workflow-design-time** targeting only. The separate ad-hoc
  `forward` action (§6.5) always still broadcasts to the whole destination
  office — narrowing wasn't extended there.

### 6.10 Admin — Application categories

```
POST /application-categories
{
  "name": "Transcript Request",
  "description": "Request an official academic transcript.",
  "form_schema": [ { "key": "reason", "label": "Reason", "type": "textarea", "required": true } ],
  "workflow_template_id": 1,
  "applicant_roles": ["student"],
  "allow_multiple_active": false,
  "is_active": true
}
GET /application-categories/{id}
PUT /application-categories/{id}
```
Admin-only writes (the read/list is the shared §6.1 endpoint). `applicant_roles: null` = open to any authenticated user.

### 6.11 Admin — Dashboard stats

```
GET /applications/dashboard
```
Admin-only (403 otherwise).
```json
{
  "message": "Success",
  "data": {
    "counts_by_status": { "pending": 3, "approved": 12, "rejected": 2, "cancelled": 1 },
    "avg_turnaround_hours_by_category": { "Transcript Request": 26.4, "Leave Application": 4.1 },
    "pending_per_office": { "Examination Officer": 2, "Transport Officer": 1 }
  }
}
```
Good fit for the admin dashboard's stat cards, alongside the existing
attendance ones.

---

## 7. Suggested Dart models

Following the app's existing convention exactly (manual `fromJson`, no
codegen, snake_case JSON → camelCase Dart — see `lib/core/models/`):

```dart
class ApplicationModel {
  final int id;
  final int applicationCategoryId;
  final String? categoryName;          // from nested "category"
  final int applicantUserId;
  final String? applicantName;
  final String? applicantRole;
  final Map<String, dynamic> formData;
  final int? currentStepId;
  final String? currentStepName;
  final String status;                 // pending | returned_for_revision | approved | rejected | cancelled
  final DateTime? submittedAt;
  final DateTime? resolvedAt;
  final List<ApplicationActionModel> timeline;   // only present on detail responses
  final List<ApplicationAttachmentModel> attachments;
  final DateTime createdAt;

  factory ApplicationModel.fromJson(Map<String, dynamic> json) => ApplicationModel(
    id: json['id'] as int,
    applicationCategoryId: json['application_category_id'] as int,
    categoryName: (json['category'] as Map<String, dynamic>?)?['name'] as String?,
    applicantUserId: json['applicant_user_id'] as int,
    applicantName: (json['applicant'] as Map<String, dynamic>?)?['name'] as String?,
    applicantRole: (json['applicant'] as Map<String, dynamic>?)?['role'] as String?,
    formData: Map<String, dynamic>.from(json['form_data'] as Map? ?? {}),
    currentStepId: json['current_step_id'] as int?,
    currentStepName: (json['current_step'] as Map<String, dynamic>?)?['name'] as String?,
    status: json['status']?.toString() ?? 'pending',
    submittedAt: json['submitted_at'] != null ? DateTime.parse(json['submitted_at']) : null,
    resolvedAt: json['resolved_at'] != null ? DateTime.parse(json['resolved_at']) : null,
    timeline: (json['timeline'] as List<dynamic>? ?? [])
        .map((e) => ApplicationActionModel.fromJson(e as Map<String, dynamic>))
        .toList(),
    attachments: (json['attachments'] as List<dynamic>? ?? [])
        .map((e) => ApplicationAttachmentModel.fromJson(e as Map<String, dynamic>))
        .toList(),
    createdAt: DateTime.parse(json['created_at']),
  );
}
```

Mirror the same pattern for `ApplicationCategoryModel` (incl. a
`FormFieldModel` for each `form_schema` entry: `key`, `label`, `type`,
`required`, `options`, `max`), `ApplicationActionModel`
(`id, workflowStepId, stepName, actorUserId, actorName, action, remarks,
forwardedToOfficeId, formDataSnapshot, attachments, createdAt`),
`ApplicationAttachmentModel` (`id, fieldKey, url, originalName, mimeType,
uploadedByUserId, createdAt` — `url` is already a fully resolved link,
same `mediaBaseUrl` handling as avatars applies if needed), `OfficeModel`
(`id, name, adminDepartmentId, adminDepartmentName, users: [{id,name,email}]`),
`WorkflowTemplateModel`, `WorkflowStepModel` (add `approverUserId` +
`approverUser: {id,name,email}?` alongside the existing office fields —
only needed for the admin side, if the admin panel is built in this app
too).

Add the new endpoint paths to `lib/core/constants/api_constants.dart`
alongside the existing ones, and a `PagedResult<T>` wrapper (`items`,
`currentPage`, `lastPage`, `total`) for this module's services instead of
reusing `listAll()`.

---

## 8. Suggested screens per role

Following the existing `lib/<role>/<entity>/` convention:

**Student / any applicant** (`lib/student/applications/`, mirrored for
teacher/hod if they can also apply — e.g. Leave Application):
- `application_category_list_screen.dart` — §6.1, "New Application" entry point.
- `application_form_screen.dart` — the **generic dynamic form renderer**
  driven by `form_schema` (§5); submits via §6.2.
- `application_list_screen.dart` — "My Applications", §6.3 no `assigned`,
  filterable by status tabs (Pending / Returned / Approved / Rejected / Cancelled).
- `application_detail_screen.dart` — §6.4, timeline view; shows a
  "Resubmit" button when `status == returned_for_revision` (reopens the
  same form pre-filled, §6.6) and a "Cancel" button when cancellable (§6.7).

**Teacher / HOD / Officials** (`lib/teacher/approvals/` or similar):
- `approvals_queue_screen.dart` — §6.3 with `assigned=1`; this is the
  "Pending Approvals" tab, badge-worthy the same way notifications are.
- Reuse `application_detail_screen.dart` from above, but show
  Approve / Reject / Forward / Comment actions (§6.5) instead of
  Resubmit/Cancel when the logged-in user is the current step holder.

**Admin** (`lib/Admin/application_tracking/`), if the admin panel is
extended to configure this module rather than using a raw API client:
- Offices list + form (§6.8)
- Workflow templates list + a step-chain builder (§6.9) — this is the
  most involved screen: an ordered list of steps, each picking
  "Office" vs "Applicant's Department HOD," and for Office steps a
  dropdown of existing offices.
- Application categories list + form (§6.10), including a **form-schema
  builder** (add/remove/reorder fields, pick `type`, toggle `required`) —
  effectively the mirror image of the dynamic form renderer from §5.
- A stats section on the admin dashboard using §6.11.

---

## 9. Notifications

No new client plumbing needed — this module writes into the **same**
`notifications` table/endpoint the attendance module already uses
(`GET /notifications`, `PUT /notifications/{id}/read`,
`DELETE /notifications/{id}`), which the app already polls on navigation.
`type` values to recognize for icon/routing purposes:

`application_submitted`, `application_approved`, `application_rejected`,
`application_forwarded`, `application_commented`, `application_resubmitted`,
`application_cancelled` — each with `related_data: {"application_id": N}`,
so tapping a notification can deep-link straight to
`application_detail_screen.dart` for that ID.

---

## 10. Demo data / accounts to test against

Password for every account below is **`password`**.

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@university.edu` | Manage offices/templates/categories |
| CS Dept. HOD | `ayesha.khan@university.edu` | Approves `applicant_department_hod` steps for CS students |
| CS Teacher | `bilal.ahmed@university.edu` | Currently holds the "Examination Officer" office on the live VPS (test data) |
| CS Student | `bscs.student1@university.edu` | Has test applications #1 (approved) and #2 (cancelled) already on their timeline |

If you want the *intended* demo categories (Transcript Request,
Leave Application, Transport Pass) instead of the ad-hoc test data above,
ask the backend side to run:
```bash
php artisan db:seed --class="App\Modules\ApplicationTracking\Database\Seeders\ApplicationTrackingSeeder"
```

---

## 11. Known gaps — nothing built for these yet

- **No push notifications** for this module either (matches the rest of the app — pull-only via `GET /notifications`).
- **No SLA/escalation** — an application can sit forever if nobody acts; no reminder system exists.
- **No `DELETE` on any admin resource** (offices, templates, categories) — only create/read/update. Retiring something means setting `is_active: false` where that field exists (categories, templates), or just leaving unused offices in place.
- **File attachments beyond images will need `file_picker`** — the app currently only has `image_picker`/`image_cropper` (avatar-oriented). A `file`-type form field expects PDFs/DOCX too, so that dependency will need adding for a complete implementation.
- **No conditional/branching form logic** — `form_schema` fields don't reference each other (no "show field B only if field A = X"). Render the array top-to-bottom, always.
