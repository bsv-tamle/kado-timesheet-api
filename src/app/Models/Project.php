<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'project_code',
        'project_name',
        'status',
        'billable_flag',
        'description',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'billable_flag' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employeeProjects(): HasMany
    {
        return $this->hasMany(EmployeeProject::class);
    }
}
