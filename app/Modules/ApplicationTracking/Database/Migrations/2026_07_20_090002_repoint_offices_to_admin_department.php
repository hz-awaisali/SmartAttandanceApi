<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offices belong to an administrative department, never an academic
     * one - repoints the (previously optional, loosely-used) academic
     * department_id link to the new admin_departments table instead.
     *
     * Nullable at the DB level deliberately, same reasoning as the
     * staff.admin_department_id migration: a hard NOT NULL risks failing
     * against already-live production offices whose name might not match
     * a known admin department. "Required" is enforced at the FormRequest
     * level going forward instead - see APPLICATION_TRACKING.md.
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->renameColumn('department_id', 'admin_department_id');
        });

        // Deterministic, exact-match backfill only - never guesses. Any
        // office whose name doesn't exactly match a known seeded pairing
        // stays NULL for an admin to assign manually.
        foreach ([
            'Examination Officer' => 'Examination Department',
            'Transport Officer' => 'Transport Department',
            'IT Officer' => 'IT Department',
        ] as $officeName => $adminDepartmentName) {
            $adminDepartmentId = DB::table('admin_departments')->where('name', $adminDepartmentName)->value('id');

            if ($adminDepartmentId) {
                DB::table('offices')->where('name', $officeName)->update(['admin_department_id' => $adminDepartmentId]);
            }
        }

        Schema::table('offices', function (Blueprint $table) {
            $table->foreign('admin_department_id')->references('id')->on('admin_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropForeign(['admin_department_id']);
        });

        // admin_department_id values are never valid academic department
        // IDs (different ID space entirely) - null them out before
        // renaming back, or re-adding the departments FK below would fail
        // against leftover values that don't exist in that table.
        DB::table('offices')->update(['admin_department_id' => null]);

        Schema::table('offices', function (Blueprint $table) {
            $table->renameColumn('admin_department_id', 'department_id');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }
};
