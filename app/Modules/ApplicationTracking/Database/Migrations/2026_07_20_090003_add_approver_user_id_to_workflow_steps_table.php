<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a workflow step optionally narrow an office-type step down to
     * one specific member of that office, instead of always broadcasting
     * to every holder - "faster resolution" for a particular officer,
     * chosen at workflow-design time. Office stays the organizational
     * anchor: this only ever narrows an office assignment, it never
     * replaces it (enforced in WorkflowTemplateController::replaceSteps()
     * - the chosen user must be a current member of the chosen office).
     */
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->foreignId('approver_user_id')->nullable()->after('approver_office_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_user_id');
        });
    }
};
