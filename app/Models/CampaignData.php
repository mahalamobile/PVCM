<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignData extends Model
{
    use HasFactory;

    protected $table = 'campaign_data';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'user_id',
        'video_url',
        'custom_fields',
        'ingested_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'custom_fields' => 'array',
        'ingested_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
