# Staff / Officer Accounts — What Changed & Frontend Implementation Guide

> Audience: whoever is wiring this into the Flutter app (CampusOS). This
> documents a **cross-cutting change**, not a new module — it extends the
> core account system (alongside admin/hod/teacher/student) specifically so
> the Digital Application Tracking module can have real, non-teaching office
> holders. Read [APPLICATION_TRACKING.md](APPLICATION_TRACKING.md) first if
> you haven't already; this document assumes you know what an `Office` is.

> ## ⚠️ Breaking changes if you've already started building against this
> This document has been revised since the `staff` role first shipped. If
> you've already written code against the original version:
> - `Office`/`Staff` responses: **`department_id`/`department` are now
>   `admin_department_id`/`admin_department`**, and they point at a brand
>   new kind of department (see §1) — not the academic one.
> - Creating a staff member now **requires** `admin_department_id` (was
>   optional before).
> - There's a real `GET /dashboard/staff` endpoint now (§5) — the earlier
>   version of this doc said there wouldn't be one.
> Everything else (the access matrix, password reset, `GET /sessions`
> behavior) is unchanged.

---

## Table of contents

1. [The problem this solves — two kinds of departments](#1-the-problem-this-solves--two-kinds-of-departments)
2. [The most important thing: the access matrix](#2-the-most-important-thing-the-access-matrix)
3. [What actually changed in the API](#3-what-actually-changed-in-the-api)
4. [New endpoints — full reference](#4-new-endpoints--full-reference)
5. [`GET /dashboard/staff`](#5-get-dashboardstaff)
6. [Suggested Dart models](#6-suggested-dart-models)
7. [Suggested screens](#7-suggested-screens)
8. [Routing logic — what to change in the app shell](#8-routing-logic--what-to-change-in-the-app-shell)
9. [Demo / test accounts](#9-demo--test-accounts)
10. [Known gaps](#10-known-gaps)

---

## 1. The problem this solves — two kinds of departments

Before this change, an `Office` (Examination Officer, Transport Officer,
...) could only ever be assigned to an existing `User` — and the only kinds
of accounts that existed were admin/hod/teacher/student. In practice that
meant "assign an office holder" always meant "pick a teacher," even for
positions that have nothing to do with teaching. A teacher who *also* holds
an office already worked correctly and needed **zero changes** — Office
assignment was always decoupled from role. What was missing was a way to
create an account for someone who **isn't** a teacher at all.

The fix has two parts:

1. A new coarse account type, `role = "staff"`, sitting alongside
   admin/hod/teacher/student. A plain account (own login, own profile) that
   can hold one or more Offices, exactly like a teacher can — but it has
   **zero attendance-module access**, because it's not an academic account.
2. **Staff belong to a real department too — just a different kind.** There
   are now two entirely separate department hierarchies:

   | | Academic (existing, unchanged) | Administrative (new) |
   |---|---|---|
   | Table | `departments` | **`admin_departments`** |
   | Who belongs | Teacher, Student, HOD | **`Staff`** |
   | Example rows | Computer Science, Zoology | Examination Department, IT Department, Registrar Office, Transport Department |
   | Endpoint | `GET/POST /api/departments` | `GET/POST /api/admin-departments` |

   These are **completely separate tables with separate IDs** — an
   `admin_department_id` of `1` has nothing to do with a `department_id` of
   `1`. `Office` also belongs to an `AdminDepartment` now (previously it
   loosely, optionally pointed at the academic table and was almost always
   left unset).

---

## 2. The most important thing: the access matrix

This is the one thing to internalize before touching any UI code — **which
modules a logged-in user should even see** now depends on their role:

| Role | Attendance module | Application Tracking module |
|---|---|---|
| `student` | ✅ full | ✅ (submit, track own applications) |
| `teacher` | ✅ full | ✅ (submit own; act on applications *only if* also holding an Office) |
| `hod` | ✅ full | ✅ (same as teacher, plus resolves automatically for `applicant_department_hod` steps) |
| `admin` | ✅ full (management) | ✅ full (including configuring Offices/Workflows/Categories/AdminDepartments) |
| **`staff`** | ❌ **none** | ✅ (submit own; act on applications for whatever Office they hold) |

**A teacher who also holds an Office is not the same thing as a `staff`
account** — don't conflate these two cases in the UI:
- **Teacher + Office** (e.g. a CS teacher who's *also* the Examination
  Officer): logs in with `role: "teacher"`, sees the full Attendance module
  *and* an Application Tracking "Pending Approvals" section. Nothing new to
  build for this case — it already worked before this change and still
  does. This person has **no `Staff` record and no `AdminDepartment`** —
  they're purely a Teacher who happens to hold an Office.
- **Pure `staff` account** (e.g. a Transport Officer who never teaches):
  logs in with `role: "staff"`, belongs to an `AdminDepartment`, should see
  **only** Application Tracking screens. No schedule tab, no "my classes,"
  no attendance anywhere in the nav.

---

## 3. What actually changed in the API

- **New role value**: `"role"` in login/register/profile responses can now
  be `"staff"`.
- **New admin-only CRUD**: `/api/staff` (§4) and `/api/admin-departments`
  (§4).
- **`Office` now belongs to an `AdminDepartment`, not an academic
  Department** — `admin_department_id`/`admin_department` in
  `OfficeResource`, required on `POST /api/offices` going forward.
- **A workflow step can now target one specific person within an Office**
  (`approver_user_id`), instead of always broadcasting to every holder —
  full detail in
  [APPLICATION_TRACKING.md](APPLICATION_TRACKING.md#specific-officer-targeting).
  Relevant here because it changes what a staff member sees: if a step
  targets a colleague specifically, you *won't* see it in your own queue
  even though you hold the same Office.
- **`GET /dashboard/staff`** — a real endpoint now (§5).
- **Password reset** accepts `staff` accounts the same way as teachers —
  `email` + `identity_no` (the staff member's `employee_no`). No change to
  request/response shape.
- **`GET /sessions`** correctly returns an empty list for `staff` — not an
  endpoint change, just documenting the guarantee.
- **Everything else is unchanged** — no existing admin/hod/teacher/student
  endpoint, response shape, or behavior was touched.

---

## 4. New endpoints — full reference

Admin-only (`Authorization: Bearer <admin token>`). Same conventions as
every other admin CRUD in this API — `{message, data}` envelope, flat array
for `index` (no pagination `meta`, unlike the Application Tracking module's
own list endpoints).

### Admin departments

```
GET  /api/admin-departments
POST /api/admin-departments        { "name": "Examination Department" }
GET  /api/admin-departments/{id}
PUT  /api/admin-departments/{id}   { "name"? }
```
Four rows already exist after migrating: **Examination Department, IT
Department, Registrar Office, Transport Department**. Admin can add more
(e.g. a future "Library Department"). Response includes `staff_count`:
```json
{ "id": 1, "name": "Examination Department", "staff_count": 2, "created_at": "..." }
```

### Staff — list / create

```
GET  /api/staff
POST /api/staff
{
  "name": "Nasir Iqbal",
  "email": "nasir.transport@university.edu",
  "password": "password123",
  "password_confirmation": "password123",
  "admin_department_id": 4,
  "employee_no": "OFF-001",
  "designation": "Transport Officer",
  "phone": "0300-1234567"
}
```
`admin_department_id` is **required** — look it up via
`GET /api/admin-departments` to populate the picker.

**201** response:
```json
{
  "message": "Staff member created",
  "data": {
    "id": 1,
    "user_id": 30,
    "admin_department_id": 4,
    "employee_no": "OFF-001",
    "designation": "Transport Officer",
    "phone": "0300-1234567",
    "name": "Nasir Iqbal",
    "email": "nasir.transport@university.edu",
    "status": "active",
    "admin_department": { "id": 4, "name": "Transport Department", "staff_count": null, "created_at": "..." },
    "created_at": "2026-07-19T11:54:11.000000Z"
  }
}
```

### Staff — show / update

```
GET /api/staff/{id}
PUT /api/staff/{id}
{ "name"?, "email"?, "admin_department_id"?, "employee_no"?, "designation"?, "phone"?, "status"? }
```
`status` accepts `active`/`inactive`. All fields on update are optional
(`sometimes`) — send only what changed. `admin_department_id`, if sent,
must be a valid id (can't be nulled out once set).

There is **no `DELETE`** for either endpoint — consistent with the rest of
this API. To retire a staff member, set `status: "inactive"`.

### Offices — the `admin_department_id` field

```
POST /api/offices
{ "name": "Examination Officer", "admin_department_id": 1, "user_ids": [30, 31] }
```
Same rename as Staff. Full Office reference (including the new
`approver_user_id` workflow-step targeting) is in
[APPLICATION_TRACKING.md](APPLICATION_TRACKING.md).

---

## 5. `GET /dashboard/staff`

A staff member's home screen — their identity, which office(s) they hold,
and their pending-approvals queue (the same data `?assigned=1` returns,
packaged as a dashboard). `role:staff` only.

```json
{
  "message": "Success",
  "data": {
    "staff": {
      "employee_no": "EX-A",
      "designation": "Controller",
      "admin_department": { "id": 1, "name": "Examination Department" }
    },
    "offices": [
      { "id": 4, "name": "Result Verification Desk" }
    ],
    "pending_count": 1,
    "pending_applications": [
      { "id": 3, "category": { "id": 4, "name": "Result Verification" }, "status": "pending", "...": "full ApplicationResource shape, same as ?assigned=1" }
    ]
  }
}
```
`pending_applications` is capped at 10 — if `pending_count` is higher, send
the user to the full `GET /applications?assigned=1` list (paginated) for
the rest. `404` if the logged-in user has no `Staff` profile (shouldn't
happen for a `role: staff` account, but mirrors the same guard
`dashboard/teacher`/`dashboard/student` already have).

---

## 6. Suggested Dart models

```dart
class AdminDepartmentModel {
  final int id;
  final String name;
  final int? staffCount;

  factory AdminDepartmentModel.fromJson(Map<String, dynamic> json) => AdminDepartmentModel(
    id: json['id'] as int,
    name: json['name'] as String,
    staffCount: json['staff_count'] as int?,
  );
}

class StaffModel {
  final int id;
  final int userId;
  final int adminDepartmentId;
  final String employeeNo;
  final String designation;
  final String? phone;
  final String name;
  final String email;
  final String status; // active | inactive
  final String? adminDepartmentName;
  final DateTime createdAt;

  factory StaffModel.fromJson(Map<String, dynamic> json) => StaffModel(
    id: json['id'] as int,
    userId: json['user_id'] as int,
    adminDepartmentId: json['admin_department_id'] as int,
    employeeNo: json['employee_no'] as String,
    designation: json['designation'] as String,
    phone: json['phone'] as String?,
    name: json['name'] as String,
    email: json['email'] as String,
    status: json['status']?.toString() ?? 'active',
    adminDepartmentName: (json['admin_department'] as Map<String, dynamic>?)?['name'] as String?,
    createdAt: DateTime.parse(json['created_at']),
  );
}
```

The already-existing `UserModel`/login response just needs its `role`
field's type to admit `"staff"` alongside the four it already handles.

---

## 7. Suggested screens

Following the existing `lib/Admin/<entity>/` convention:

- **`lib/Admin/admin_departments/`** — a simple list + create/rename form
  for `AdminDepartment` (small — 4 fields max, no complex form).
- **`lib/Admin/staff/staff_list_screen.dart`** — `GET /api/staff`.
- **`lib/Admin/staff/staff_form_screen.dart`** — create/edit; the
  `admin_department_id` picker should be populated from
  `GET /api/admin-departments` (fetch once, cache — it changes rarely).
- **`lib/staff/dashboard/staff_dashboard_screen.dart`** — the staff
  member's own home screen, `GET /api/dashboard/staff` (§5).
- **The rest of the staff experience reuses the Application Tracking
  screens** a teacher-with-an-office would use (submit, application
  detail, act) — see
  [APPLICATION_TRACKING.md §8](APPLICATION_TRACKING.md#8-suggested-screens-per-role).

---

## 8. Routing logic — what to change in the app shell

Wherever the app branches on role to decide the bottom nav/drawer/home
screen, add a `staff` case that shows **only**:
- The staff dashboard (§5) as the home screen
- Application Tracking screens (submit, my applications, pending
  approvals)
- Profile / notifications (already generic)

...and hides everything attendance-related: no schedule tab, no sessions,
no "today's classes."

---

## 9. Demo / test accounts

`ApplicationTrackingSeeder` now creates **real `staff` accounts** (it used
to create `role: teacher` accounts as a stand-in, before `staff` existed):

| Email | Password | Employee No | Admin Department | Designation |
|---|---|---|---|---|
| `examination.officer@university.edu` | `password` | `EMP-EX-001` | Examination Department | Examination Officer |
| `transport.officer@university.edu` | `password` | `EMP-TR-001` | Transport Department | Transport Officer |
| `it.officer@university.edu` | `password` | `EMP-IT-001` | IT Department | IT Officer |

Run (or re-run) it with:
```bash
php artisan db:seed --class="App\Modules\ApplicationTracking\Database\Seeders\ApplicationTrackingSeeder"
```
Note: if this seeder already ran on your environment *before* this change,
those three accounts were created as `role: teacher` and won't be
retroactively converted — `firstOrCreate` only sets attributes on first
creation. On the live VPS specifically, there's also one real test account
(`deploy-check@university.edu`, employee_no `OFF-VERIFY-1`) with no
`admin_department_id` set (created before `AdminDepartment` existed) — it
needs a manual `PUT /api/staff/{id}` to assign one if you want to use it.

---

## 10. Known gaps

- **No self-service registration for staff** — accounts are admin-created
  only (`POST /api/staff`). Don't offer `staff` as a role option on any
  public "sign up" screen.
- **OTP-based password reset** is planned but not implemented for anyone
  (student/teacher/staff alike) — the current `email + identifier` check
  is what exists today; don't build OTP UI against this API yet.
- **`pending_applications` on the dashboard is capped at 10** with no
  pagination of its own — use `GET /applications?assigned=1` for the full,
  paginated list.
