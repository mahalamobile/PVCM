<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDataDuplicate extends Model
{
    use HasFactory;

    protected $table = 'campaign_data_duplicates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'user_id',
        'strategy',
        'incoming_payload',
        'existing_payload',
        'resolution',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'incoming_payload' => 'array',
        'existing_payload' => 'array',
    ];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
