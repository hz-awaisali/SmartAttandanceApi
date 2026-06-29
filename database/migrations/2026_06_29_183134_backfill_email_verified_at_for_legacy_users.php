<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email verification (OTP) was added after this app already had real
     * users (seeded admin/hod/teacher/student accounts, plus anyone who'd
     * registered before this feature shipped). Login now rejects anyone
     * with a null email_verified_at, which would otherwise lock all of
     * them out even though they were never sent a code.
     *
     * A user only legitimately needs to go through verification if a code
     * was actually issued to them (verification_code is set), so this only
     * backfills accounts that predate the feature - it leaves any real
     * pending self-registration untouched.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNull('verification_code')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible - we don't know which rows we touched vs. which
        // were already verified before this ran.
    }
};
