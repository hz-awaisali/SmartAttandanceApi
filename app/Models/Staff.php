<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = ['user_id', 'admin_department_id', 'employee_no', 'designation', 'phone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminDepartment(): BelongsTo
    {
        return $this->belongsTo(AdminDepartment::class);
    }
}
