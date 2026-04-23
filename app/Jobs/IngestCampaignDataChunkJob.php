<?php

namespace App\Jobs;

use App\Actions\HandleCampaignDataChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IngestCampaignDataChunkJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, array{user_id: string, video_url: string, custom_fields?: array<string, mixed>|null}>  $rows
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $rows,
    ) {
        $this->onQueue('campaign-data');
    }

    public function handle(): void
    {
        $strategy = (string) config('campaign.duplicate_strategy', 'update');

        (new HandleCampaignDataChunk($strategy))->execute($this->campaignId, $this->rows);
    }
}
