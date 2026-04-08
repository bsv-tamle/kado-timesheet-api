<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimesheetEntryDetail extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'timesheet_entry_id',
        'project_id',
        'work_type_id',
        'hours_worked',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'hours_worked' => 'float',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimesheetEntry::class, 'timesheet_entry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}

