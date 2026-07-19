# Staff / Officer Accounts — What Changed & Frontend Implementation Guide

> Audience: whoever is wiring this into the Flutter app (CampusOS). This
> documents a **cross-cutting change**, not a new module — it extends the
> core account system (alongside admin/hod/teacher/student) specifically so
> the Digital Application Tracking module can have real, non-teaching office
> holders. Read [APPLICATION_TRACKING.md](APPLICATION_TRACKING.md) first if
> you haven't already; this document assumes you know what an `Office` is.

---

## Table of contents

1. [The problem this solves](#1-the-problem-this-solves)
2. [The most important thing: the access matrix](#2-the-most-important-thing-the-access-matrix)
3. [What actually changed in the API](#3-what-actually-changed-in-the-api)
4. [New endpoints — full reference](#4-new-endpoints--full-reference)
5. [Suggested Dart model](#5-suggested-dart-model)
6. [Suggested screens](#6-suggested-screens)
7. [Routing logic — what to change in the app shell](#7-routing-logic--what-to-change-in-the-app-shell)
8. [Demo / test accounts](#8-demo--test-accounts)
9. [Known gaps](#9-known-gaps)

---

## 1. The problem this solves

Before this change, an `Office` (Examination Officer, Transport Officer,
...) could only ever be assigned to an existing `User` — and the only kinds
of accounts that existed were admin/hod/teacher/student. In practice that
meant "assign an office holder" always meant "pick a teacher," even for
positions that have nothing to do with teaching (a Registrar, an IT
Officer). A teacher who *also* holds an office already worked correctly and
needed **zero changes** — Office assignment was always decoupled from role.
What was missing was a way to create an account for someone who **isn't**
a teacher at all.

The fix: a new coarse account type, `role = "staff"`, sitting alongside
admin/hod/teacher/student. It's a plain account (own login, own profile)
that can hold one or more Offices, exactly like a teacher can — but it has
**zero attendance-module access**, because it's not an academic account.

---

## 2. The most important thing: the access matrix

This is the one thing to internalize before touching any UI code — **which
modules a logged-in user should even see** now depends on their role in a
way it didn't before:

| Role | Attendance module | Application Tracking module |
|---|---|---|
| `student` | ✅ full | ✅ (submit, track own applications) |
| `teacher` | ✅ full | ✅ (submit own; act on applications *only if* also holding an Office) |
| `hod` | ✅ full | ✅ (same as teacher, plus resolves automatically for `applicant_department_hod` steps) |
| `admin` | ✅ full (management) | ✅ full (including configuring Offices/Workflows/Categories) |
| **`staff`** (new) | ❌ **none** | ✅ (submit own; act on applications for whatever Office they hold) |

**A teacher who also holds an Office is not the same thing as a `staff`
account** — don't conflate these two cases in the UI:
- **Teacher + Office** (e.g. Bilal Ahmed is a CS teacher *and* the
  Examination Officer): logs in with `role: "teacher"`, sees the full
  Attendance module (their schedule, sessions, etc.) *and* an Application
  Tracking "Pending Approvals" section because they happen to hold an
  Office. Nothing new to build for this case — it already worked before
  this change and still does.
- **Pure `staff` account** (e.g. a Transport Officer who never teaches):
  logs in with `role: "staff"`, should see **only** Application Tracking
  screens. No schedule tab, no "my classes," no attendance anywhere in
  the nav. If they try to hit an attendance-module endpoint anyway, the
  API returns `403` — but the UI shouldn't offer that path to begin with.

---

## 3. What actually changed in the API

- **New role value**: `"role"` in login/register/profile responses can now
  be `"staff"`, not just the four you already handle.
- **New admin-only CRUD**: `/api/staff` (see §4).
- **Password reset** now also accepts `staff` accounts, verified the same
  way as teachers — `email` + `identity_no` where `identity_no` is the
  staff member's `employee_no`. No change to the request/response shape of
  `POST /auth/forgot-password` / `POST /auth/reset-password` — just a new
  role that's now eligible.
- **`GET /sessions` behavior note**: if you ever call this endpoint from a
  `staff`-logged-in session (you shouldn't need to — see §2), it now
  correctly returns an **empty list** rather than accidentally leaking
  every session. Not an endpoint change, just documenting the guarantee.
- **Everything else is unchanged.** No existing endpoint, response shape,
  or behavior for admin/hod/teacher/student was touched. Office assignment
  (`PUT /offices/{id}` with `user_ids`), application submission, and
  acting on applications all work for a `staff` user exactly the same way
  they already work for a teacher — nothing module-specific needed to be
  built for this.

---

## 4. New endpoints — full reference

Admin-only (`Authorization: Bearer <admin token>`). Same conventions as
every other admin CRUD in this API — `{message, data}` envelope, flat
array for `index` (no pagination `meta`, unlike the Application Tracking
module's own list endpoints — see the note in the main
[README](README.md#api-surface)).

### List / create

```
GET  /api/staff
POST /api/staff
{
  "name": "Nasir Iqbal",
  "email": "nasir.transport@university.edu",
  "password": "password123",
  "password_confirmation": "password123",
  "department_id": null,
  "employee_no": "OFF-001",
  "designation": "Transport Officer",
  "phone": "0300-1234567"
}
```
`department_id` is almost always `null` in practice — these are typically
university-wide positions, not department-bound (the field exists for the
rare case it's needed, same shape as `Teacher.department_id`).

**201** response:
```json
{
  "message": "Staff member created",
  "data": {
    "id": 1,
    "user_id": 30,
    "department_id": null,
    "employee_no": "OFF-001",
    "designation": "Transport Officer",
    "phone": "0300-1234567",
    "name": "Nasir Iqbal",
    "email": "nasir.transport@university.edu",
    "status": "active",
    "department": null,
    "created_at": "2026-07-19T11:54:11.000000Z"
  }
}
```

### Show / update

```
GET /api/staff/{id}
PUT /api/staff/{id}
{ "name"?, "email"?, "department_id"?, "employee_no"?, "designation"?, "phone"?, "status"? }
```
`status` accepts `active`/`inactive` — same account-deactivation pattern
as teachers/students. All fields on update are optional (`sometimes`
validation) — send only what changed.

There is **no `DELETE /api/staff/{id}`** — consistent with the rest of
this API (no destroy routes anywhere). To retire a staff member, set
`status: "inactive"` and/or remove them from any Offices via
`PUT /api/offices/{id}`.

---

## 5. Suggested Dart model

Mirrors the existing `TeacherModel` almost exactly (same manual-`fromJson`
convention as the rest of the app):

```dart
class StaffModel {
  final int id;
  final int userId;
  final int? departmentId;
  final String employeeNo;
  final String designation;
  final String? phone;
  final String name;
  final String email;
  final String status; // active | inactive
  final String? departmentName; // from nested "department", if present
  final DateTime createdAt;

  factory StaffModel.fromJson(Map<String, dynamic> json) => StaffModel(
    id: json['id'] as int,
    userId: json['user_id'] as int,
    departmentId: json['department_id'] as int?,
    employeeNo: json['employee_no'] as String,
    designation: json['designation'] as String,
    phone: json['phone'] as String?,
    name: json['name'] as String,
    email: json['email'] as String,
    status: json['status']?.toString() ?? 'active',
    departmentName: (json['department'] as Map<String, dynamic>?)?['name'] as String?,
    createdAt: DateTime.parse(json['created_at']),
  );
}
```

The already-existing `UserModel`/login response just needs its `role`
field's type to admit `"staff"` alongside the four it already handles —
if that's a Dart enum today, add the new case; if it's a plain `String`,
nothing to change there at all.

---

## 6. Suggested screens

Following the existing `lib/Admin/<entity>/` convention (mirrors however
Teacher management is currently built):

- **`lib/Admin/staff/staff_list_screen.dart`** — `GET /api/staff`, same
  list/search UX as the existing teacher list.
- **`lib/Admin/staff/staff_form_screen.dart`** — create/edit, §4's fields.
  `department_id` should probably default to "None / University-wide" in
  the picker rather than forcing a department choice, since that's the
  common case.
- **No new screens needed for the `staff` user's own experience** — once
  logged in, a staff account uses the **exact same** Application Tracking
  screens a teacher-with-an-office would use (submit application, "Pending
  Approvals" queue, act on an application). See
  [APPLICATION_TRACKING.md §8](APPLICATION_TRACKING.md#8-suggested-screens-per-role)
  for those. The only thing that changes for staff is *which other
  screens/tabs are hidden* — see §7.

---

## 7. Routing logic — what to change in the app shell

Wherever the app currently branches on role to decide the bottom
nav/drawer/home screen (student vs teacher vs hod vs admin), add a
`staff` case that shows **only**:
- Application Tracking screens (submit, my applications, pending
  approvals if they hold an office)
- Profile / notifications (already generic, no role-specific handling
  needed there)

...and hides everything attendance-related: no schedule tab, no sessions,
no "today's classes," no dashboard stats tied to attendance. If there's a
shared "dashboard" concept, staff can reuse whatever the
Application-Tracking-side home screen is (per
[APPLICATION_TRACKING.md §8](APPLICATION_TRACKING.md#8-suggested-screens-per-role),
there's no dedicated staff dashboard endpoint — the "assigned to me" list
*is* their home screen).

---

## 8. Demo / test accounts

No staff accounts are seeded by default in `DatabaseSeeder.php` or
`ApplicationTrackingSeeder.php` — create one via `POST /api/staff` as
admin to test with. Example used during verification (already on the
live VPS if you want to test against it directly rather than local):

| Field | Value |
|---|---|
| Email | `deploy-check@university.edu` |
| Password | `password123` |
| Employee No | `OFF-VERIFY-1` |
| Role | `staff` |

This account isn't assigned to any Office yet — assign it via
`PUT /api/offices/{id}` with `user_ids` if you want to test the "act on an
application" flow against it.

---

## 9. Known gaps

- **No self-service registration for staff** — accounts are admin-created
  only (`POST /api/staff`), unlike teacher/student which also have a public
  `POST /auth/register` path. If a "sign up" screen exists in the app,
  don't offer `staff` as a role option there.
- **No dedicated staff dashboard endpoint** — intentional, see §6/§7.
- **OTP-based password reset** is planned but not implemented for anyone
  (student/teacher/staff alike) — the current `email + identifier` check
  is what exists today; don't build OTP UI against this API yet.
