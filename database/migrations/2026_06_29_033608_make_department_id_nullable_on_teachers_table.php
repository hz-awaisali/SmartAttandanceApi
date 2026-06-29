<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teachers are not bound to a single department here (e.g. one teacher
     * may teach courses across multiple departments) — the real assignment
     * lives on the timetable, so department on the teacher record itself
     * becomes optional.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        DB::statement('ALTER TABLE teachers MODIFY department_id BIGINT UNSIGNED NULL');

        Schema::table('teachers', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        DB::statement('ALTER TABLE teachers MODIFY department_id BIGINT UNSIGNED NOT NULL');

        Schema::table('teachers', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
        });
    }
};
