<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCampaignDataRequest;
use App\Jobs\IngestCampaignDataChunkJob;
use App\Models\Campaign;
use App\Models\IdempotencyKey;
use App\Support\IdempotencyHasher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CampaignDataController extends Controller
{
    public function store(StoreCampaignDataRequest $request, Campaign $campaign): JsonResponse
    {
        $rows = $request->validated()['data'];
        $chunkSize = max(1, (int) config('campaign.ingest_chunk_size', 500));
        $idempotencyHeader = (string) config('campaign.idempotency.header', 'Idempotency-Key');
        $idempotencyKey = trim((string) $request->header($idempotencyHeader, ''));

        if ($idempotencyKey === '') {
            return response()->json([
                'message' => "Missing required header: {$idempotencyHeader}.",
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($idempotencyKey) > 255) {
            return response()->json([
                'message' => 'Idempotency key is too long. Maximum length is 255 characters.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $endpoint = 'campaign-data-ingestion';
        $requestHash = IdempotencyHasher::hash([
            'campaign_id' => $campaign->id,
            'rows' => $rows,
        ]);
        $ttlHours = max(1, (int) config('campaign.idempotency.ttl_hours', 48));
        $expiresAt = Carbon::now()->addHours($ttlHours);

        $idempotencyRecord = null;
        $created = false;

        try {
            $idempotencyRecord = DB::transaction(function () use (
                $campaign,
                $endpoint,
                $idempotencyKey,
                $requestHash,
                $expiresAt
            ): IdempotencyKey {
                return IdempotencyKey::query()->create([
                    'client_id' => $campaign->client_id,
                    'campaign_id' => $campaign->id,
                    'endpoint' => $endpoint,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'expires_at' => $expiresAt,
                    'last_seen_at' => Carbon::now(),
                ]);
            });

            $created = true;
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            $existing = IdempotencyKey::query()
                ->where('client_id', $campaign->client_id)
                ->where('endpoint', $endpoint)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $existing->forceFill(['last_seen_at' => Carbon::now()])->save();

            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'message' => 'Idempotency key conflict: this key was already used with a different payload.',
                ], Response::HTTP_CONFLICT);
            }

            if ($existing->status === 'completed' && $existing->response_body !== null && $existing->response_code !== null) {
                return response()->json(
                    $existing->response_body,
                    (int) $existing->response_code,
                    ['X-Idempotency-Replay' => 'true']
                );
            }

            return response()->json([
                'message' => 'Campaign data is already accepted for processing with this idempotency key.',
                'campaign_id' => $campaign->id,
                'idempotency_key' => $idempotencyKey,
            ], Response::HTTP_ACCEPTED, ['X-Idempotency-Replay' => 'true']);
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            IngestCampaignDataChunkJob::dispatch($campaign->id, $chunk);
        }

        $responsePayload = [
            'message' => 'Campaign data accepted for processing.',
            'campaign_id' => $campaign->id,
            'received_records' => count($rows),
            'chunk_size' => $chunkSize,
            'duplicate_strategy' => config('campaign.duplicate_strategy', 'update'),
            'idempotency_key' => $idempotencyKey,
        ];

        if ($created && $idempotencyRecord !== null) {
            $idempotencyRecord->forceFill([
                'status' => 'completed',
                'response_code' => Response::HTTP_ACCEPTED,
                'response_body' => $responsePayload,
                'last_seen_at' => Carbon::now(),
            ])->save();
        }

        return response()->json($responsePayload, Response::HTTP_ACCEPTED, ['X-Idempotency-Replay' => 'false']);
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
