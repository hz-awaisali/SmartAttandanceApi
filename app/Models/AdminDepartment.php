<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The administrative counterpart to Department (Examination Department, IT
 * Department, Registrar Office, Transport Department, ...) - where Staff
 * and Offices belong, never an academic Department.
 */
class AdminDepartment extends Model
{
    protected $fillable = ['name'];

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
