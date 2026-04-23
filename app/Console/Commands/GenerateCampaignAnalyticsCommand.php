<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignData;
use App\Models\CampaignDataDuplicate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'campaigns:analytics')]
class GenerateCampaignAnalyticsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'campaigns:analytics
        {--campaign_id= : Restrict report to one campaign}
        {--from= : Filter with start_date >= this datetime}
        {--to= : Filter with start_date <= this datetime}
        {--format=table : table|json}';

    /**
     * @var string
     */
    protected $description = 'Generate campaign analytics and duplicate visibility metrics';

    public function handle(): int
    {
        $campaignId = $this->option('campaign_id');
        $from = $this->option('from');
        $to = $this->option('to');
        $format = strtolower((string) $this->option('format'));

        $campaignsQuery = Campaign::query();

        if ($campaignId !== null) {
            $campaignsQuery->where('id', (int) $campaignId);
        }

        if ($from !== null) {
            $campaignsQuery->where('start_date', '>=', $from);
        }

        if ($to !== null) {
            $campaignsQuery->where('start_date', '<=', $to);
        }

        $campaignIds = $campaignsQuery->pluck('id');

        $now = CarbonImmutable::now();
        $totalCampaigns = $campaignIds->count();
        $activeCampaigns = Campaign::query()
            ->whereIn('id', $campaignIds)
            ->where('start_date', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $now);
            })
            ->count();

        $totalDataRows = CampaignData::query()
            ->whereIn('campaign_id', $campaignIds)
            ->count();

        $duplicateCount = CampaignDataDuplicate::query()
            ->whereIn('campaign_id', $campaignIds)
            ->count();

        $perCampaignRows = CampaignData::query()
            ->selectRaw('campaign_id, COUNT(*) as total_rows')
            ->whereIn('campaign_id', $campaignIds)
            ->groupBy('campaign_id')
            ->orderByDesc('total_rows')
            ->get();

        $payload = [
            'generated_at' => $now->toIso8601String(),
            'filters' => [
                'campaign_id' => $campaignId !== null ? (int) $campaignId : null,
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'total_campaigns' => $totalCampaigns,
                'active_campaigns' => $activeCampaigns,
                'total_personalized_videos' => $totalDataRows,
                'total_duplicates' => $duplicateCount,
            ],
            'campaign_breakdown' => $perCampaignRows->map(fn ($row): array => [
                'campaign_id' => (int) $row->campaign_id,
                'total_rows' => (int) $row->total_rows,
            ])->values()->all(),
        ];

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total campaigns', $payload['summary']['total_campaigns']],
                ['Active campaigns', $payload['summary']['active_campaigns']],
                ['Total personalized videos', $payload['summary']['total_personalized_videos']],
                ['Total duplicates', $payload['summary']['total_duplicates']],
            ]
        );

        if ($payload['campaign_breakdown'] !== []) {
            $this->newLine();
            $this->info('Breakdown by campaign');
            $this->table(
                ['Campaign ID', 'Rows'],
                array_map(static fn (array $row): array => [
                    $row['campaign_id'],
                    $row['total_rows'],
                ], $payload['campaign_breakdown'])
            );
        }

        return self::SUCCESS;
    }
}
