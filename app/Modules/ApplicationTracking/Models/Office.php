<?php

namespace App\Modules\ApplicationTracking\Models;

use App\Models\AdminDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Office extends Model
{
    protected $fillable = ['name', 'admin_department_id'];

    public function adminDepartment(): BelongsTo
    {
        return $this->belongsTo(AdminDepartment::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'office_user');
    }
}
