<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTrackSDK\SharedResources\Modules\Matter\Database\Factories\MilestoneFactory;

class Milestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'matter_id',
        'name',
        'description',
        'status',
        'position',
        'due_date',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): MilestoneFactory
    {
        return MilestoneFactory::new();
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
