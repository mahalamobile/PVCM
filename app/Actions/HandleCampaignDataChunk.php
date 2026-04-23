<?php

namespace App\Actions;

use App\Models\CampaignData;
use App\Models\CampaignDataDuplicate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class HandleCampaignDataChunk
{
    public function __construct(
        private readonly string $duplicateStrategy = 'update',
    ) {
    }

    /**
     * @param  array<int, array{user_id: string, video_url: string, custom_fields?: array<string, mixed>|null}>  $rows
     */
    public function execute(int $campaignId, array $rows): void
    {
        $strategy = $this->resolvedStrategy();
        $now = CarbonImmutable::now();
        $userIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['user_id'],
            $rows
        )));

        $existingRows = CampaignData::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $upsertRows = [];
        $duplicateLogs = [];

        foreach ($rows as $row) {
            $existing = $existingRows->get($row['user_id']);
            $incomingCustomFields = is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : [];

            if ($existing !== null) {
                $duplicateLogs[] = $this->duplicateLogRow($campaignId, $row, $existing->toArray(), $strategy, $now);

                if ($strategy === 'reject') {
                    continue;
                }
            }

            $finalCustomFields = $incomingCustomFields;

            if ($strategy === 'merge' && $existing !== null) {
                $existingCustomFields = is_array($existing->custom_fields) ? $existing->custom_fields : [];
                $finalCustomFields = array_replace($existingCustomFields, $incomingCustomFields);
            }

            $upsertRows[] = [
                'campaign_id' => $campaignId,
                'user_id' => $row['user_id'],
                'video_url' => $row['video_url'],
                'custom_fields' => json_encode($finalCustomFields, JSON_THROW_ON_ERROR),
                'ingested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($upsertRows, $duplicateLogs): void {
            if ($upsertRows !== []) {
                CampaignData::query()->upsert(
                    $upsertRows,
                    ['campaign_id', 'user_id'],
                    ['video_url', 'custom_fields', 'ingested_at', 'updated_at']
                );
            }

            if ($duplicateLogs !== []) {
                CampaignDataDuplicate::query()->insert($duplicateLogs);
            }
        });
    }

    /**
     * @param  array{user_id: string, video_url: string, custom_fields?: array<string, mixed>|null}  $incomingRow
     * @param  array<string, mixed>  $existingRow
     */
    private function duplicateLogRow(int $campaignId, array $incomingRow, array $existingRow, string $strategy, CarbonImmutable $now): array
    {
        return [
            'campaign_id' => $campaignId,
            'user_id' => $incomingRow['user_id'],
            'strategy' => $strategy,
            'incoming_payload' => json_encode($incomingRow, JSON_THROW_ON_ERROR),
            'existing_payload' => json_encode([
                'user_id' => $existingRow['user_id'] ?? null,
                'video_url' => $existingRow['video_url'] ?? null,
                'custom_fields' => $existingRow['custom_fields'] ?? null,
                'ingested_at' => $existingRow['ingested_at'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'resolution' => match ($strategy) {
                'reject' => 'ignored',
                'merge' => 'merged',
                default => 'updated',
            },
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function resolvedStrategy(): string
    {
        $strategy = strtolower($this->duplicateStrategy);

        if (in_array($strategy, ['update', 'reject', 'merge'], true)) {
            return $strategy;
        }

        return 'update';
    }
}
