<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignData;
use App\Models\CampaignDataDuplicate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    public function __invoke(): Response
    {
        $now = CarbonImmutable::now();

        $metrics = [
            '# HELP pvcm_campaigns_total Total number of campaigns.',
            '# TYPE pvcm_campaigns_total gauge',
            'pvcm_campaigns_total '.Campaign::query()->count(),
            '# HELP pvcm_campaign_data_total Total personalized videos ingested.',
            '# TYPE pvcm_campaign_data_total gauge',
            'pvcm_campaign_data_total '.CampaignData::query()->count(),
            '# HELP pvcm_campaign_duplicates_total Total duplicate payloads seen.',
            '# TYPE pvcm_campaign_duplicates_total counter',
            'pvcm_campaign_duplicates_total '.CampaignDataDuplicate::query()->count(),
            '# HELP pvcm_campaigns_active Current active campaigns.',
            '# TYPE pvcm_campaigns_active gauge',
            'pvcm_campaigns_active '.Campaign::query()
                ->where('start_date', '<=', $now)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('end_date')->orWhere('end_date', '>=', $now);
                })
                ->count(),
            '# HELP pvcm_jobs_pending Total jobs waiting in queue.',
            '# TYPE pvcm_jobs_pending gauge',
            'pvcm_jobs_pending '.DB::table('jobs')->count(),
            '# HELP pvcm_jobs_campaign_data_pending Jobs waiting in campaign-data queue.',
            '# TYPE pvcm_jobs_campaign_data_pending gauge',
            'pvcm_jobs_campaign_data_pending '.DB::table('jobs')->where('queue', 'campaign-data')->count(),
        ];

        $perCampaign = DB::table('campaign_data as cd')
            ->leftJoin('campaigns as c', 'c.id', '=', 'cd.campaign_id')
            ->select('cd.campaign_id', 'c.name', DB::raw('count(*) as total'))
            ->groupBy('cd.campaign_id', 'c.name')
            ->get();

        if ($perCampaign->isNotEmpty()) {
            $metrics[] = '# HELP pvcm_campaign_data_by_campaign Ingested videos per campaign.';
            $metrics[] = '# TYPE pvcm_campaign_data_by_campaign gauge';

            foreach ($perCampaign as $row) {
                $metrics[] = sprintf(
                    'pvcm_campaign_data_by_campaign{campaign_id="%d",campaign_name="%s"} %d',
                    (int) $row->campaign_id,
                    $this->escapeLabel((string) ($row->name ?? '')),
                    (int) $row->total,
                );
            }
        }

        return response(implode("\n", $metrics)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4',
        ]);
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value,
        );
    }
}
