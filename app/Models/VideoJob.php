<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJob extends Model
{
    protected $fillable = [
        'title',
        'duration',
        'ffmpeg_path',
        'hide_timer',
        'status',
        'output_file',
        'log',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'duration' => 'integer',
        'hide_timer' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isComplete(): bool
    {
        return $this->status === 'completed' && $this->output_file !== null;
    }
}
