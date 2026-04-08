<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimesheetEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'work_date',
        'period_id',
        'total_hours',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'total_hours' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(TimesheetEntryDetail::class, 'timesheet_entry_id');
    }
}

