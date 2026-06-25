<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobLog extends Model
{
    protected $fillable = [
        'uuid',
        'job_name',
        'queue',
        'status',
        'job_data',
        'exception',
        'attempt',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'job_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return class_basename($this->job_name);
    }
}
