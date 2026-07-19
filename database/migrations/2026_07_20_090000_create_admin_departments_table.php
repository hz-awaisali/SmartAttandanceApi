<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Administrative departments (Examination Department, IT Department,
     * Registrar Office, Transport Department, ...) - the non-teaching
     * counterpart to the academic "departments" table. Staff and Offices
     * belong here, never to an academic department.
     *
     * The four baseline rows are inserted here rather than in a seeder:
     * they're reference data every environment needs (matching the
     * pre-existing "Examination/Transport/IT Officer" demo accounts),
     * not optional demo data.
     */
    public function up(): void
    {
        Schema::create('admin_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();

            $table->index('created_at');
        });

        DB::table('admin_departments')->insert([
            ['name' => 'Examination Department', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'IT Department', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Registrar Office', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transport Department', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_departments');
    }
};
