<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff belong to an administrative department, never an academic one -
     * repoints the (previously unused, always-null-in-practice) academic
     * department_id link to the new admin_departments table instead.
     *
     * Nullable at the DB level deliberately: a hard NOT NULL here risks
     * failing against already-live production data whose staff.designation
     * might not exactly match a known admin department name. "Required"
     * is enforced at the FormRequest level for new staff going forward
     * (StoreStaffRequest) instead - see STAFF_ACCOUNTS.md.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->renameColumn('department_id', 'admin_department_id');
        });

        // Deterministic, exact-match backfill only - never guesses. Any
        // staff row whose designation doesn't exactly match a known admin
        // department name stays NULL for an admin to assign manually.
        foreach ([
            'Examination Officer' => 'Examination Department',
            'Transport Officer' => 'Transport Department',
            'IT Officer' => 'IT Department',
        ] as $designation => $adminDepartmentName) {
            $adminDepartmentId = DB::table('admin_departments')->where('name', $adminDepartmentName)->value('id');

            if ($adminDepartmentId) {
                DB::table('staff')->where('designation', $designation)->update(['admin_department_id' => $adminDepartmentId]);
            }
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->foreign('admin_department_id')->references('id')->on('admin_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['admin_department_id']);
        });

        // admin_department_id values are never valid academic department
        // IDs (different ID space entirely) - null them out before
        // renaming back, or re-adding the departments FK below would fail
        // against leftover values that don't exist in that table.
        DB::table('staff')->update(['admin_department_id' => null]);

        Schema::table('staff', function (Blueprint $table) {
            $table->renameColumn('admin_department_id', 'department_id');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }
};
