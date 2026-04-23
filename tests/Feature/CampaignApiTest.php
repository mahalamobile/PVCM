<?php

namespace Tests\Feature;

use App\Jobs\IngestCampaignDataChunkJob;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_campaign_requires_bearer_token(): void
    {
        $client = Client::query()->create(['name' => 'Acme']);

        $response = $this->postJson('/api/campaigns', [
            'client_id' => $client->id,
            'name' => 'New Launch',
            'start_date' => now()->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    }

    public function test_create_campaign_returns_created_resource(): void
    {
        config()->set('services.internal_api.key', 'test-token');
        $client = Client::query()->create(['name' => 'Acme']);

        $response = $this->withToken('test-token')->postJson('/api/campaigns', [
            'client_id' => $client->id,
            'name' => 'New Launch',
            'start_date' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.name', 'New Launch');
    }

    public function test_ingestion_endpoint_dispatches_queue_jobs(): void
    {
        Queue::fake();
        config()->set('services.internal_api.key', 'test-token');

        $client = Client::query()->create(['name' => 'Acme']);
        $campaign = Campaign::query()->create([
            'client_id' => $client->id,
            'name' => 'Queue Test',
            'start_date' => now(),
        ]);

        config()->set('campaign.ingest_chunk_size', 2);

        $payload = [
            'data' => [
                ['user_id' => 'u-1', 'video_url' => 'https://example.com/1.mp4'],
                ['user_id' => 'u-2', 'video_url' => 'https://example.com/2.mp4'],
                ['user_id' => 'u-3', 'video_url' => 'https://example.com/3.mp4'],
            ],
        ];

        $response = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'ingest-key-001')
            ->postJson("/api/campaigns/{$campaign->id}/data", $payload);

        $response->assertAccepted()
            ->assertJsonPath('received_records', 3);

        Queue::assertPushed(IngestCampaignDataChunkJob::class, 2);
    }

    public function test_ingestion_requires_idempotency_key(): void
    {
        Queue::fake();
        config()->set('services.internal_api.key', 'test-token');

        $client = Client::query()->create(['name' => 'Acme']);
        $campaign = Campaign::query()->create([
            'client_id' => $client->id,
            'name' => 'Idempotency Required',
            'start_date' => now(),
        ]);

        $response = $this->withToken('test-token')->postJson("/api/campaigns/{$campaign->id}/data", [
            'data' => [
                ['user_id' => 'u-1', 'video_url' => 'https://example.com/1.mp4'],
            ],
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Missing required header: Idempotency-Key.');

        Queue::assertNothingPushed();
    }

    public function test_ingestion_replay_with_same_key_and_payload_is_returned_without_new_jobs(): void
    {
        Queue::fake();
        config()->set('services.internal_api.key', 'test-token');

        $client = Client::query()->create(['name' => 'Acme']);
        $campaign = Campaign::query()->create([
            'client_id' => $client->id,
            'name' => 'Replay Test',
            'start_date' => now(),
        ]);

        $payload = [
            'data' => [
                ['user_id' => 'u-1', 'video_url' => 'https://example.com/1.mp4'],
                ['user_id' => 'u-2', 'video_url' => 'https://example.com/2.mp4'],
            ],
        ];

        $first = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'same-key')
            ->postJson("/api/campaigns/{$campaign->id}/data", $payload);

        $second = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'same-key')
            ->postJson("/api/campaigns/{$campaign->id}/data", $payload);

        $first->assertAccepted();
        $second->assertAccepted()
            ->assertHeader('X-Idempotency-Replay', 'true')
            ->assertJsonPath('idempotency_key', 'same-key');

        Queue::assertPushed(IngestCampaignDataChunkJob::class, 1);
    }

    public function test_ingestion_reusing_key_with_different_payload_returns_conflict(): void
    {
        Queue::fake();
        config()->set('services.internal_api.key', 'test-token');

        $client = Client::query()->create(['name' => 'Acme']);
        $campaign = Campaign::query()->create([
            'client_id' => $client->id,
            'name' => 'Conflict Test',
            'start_date' => now(),
        ]);

        $firstPayload = [
            'data' => [
                ['user_id' => 'u-1', 'video_url' => 'https://example.com/1.mp4'],
            ],
        ];

        $differentPayload = [
            'data' => [
                ['user_id' => 'u-1', 'video_url' => 'https://example.com/DIFFERENT.mp4'],
            ],
        ];

        $first = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'conflict-key')
            ->postJson("/api/campaigns/{$campaign->id}/data", $firstPayload);

        $second = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'conflict-key')
            ->postJson("/api/campaigns/{$campaign->id}/data", $differentPayload);

        $first->assertAccepted();
        $second->assertStatus(409)
            ->assertJsonPath('message', 'Idempotency key conflict: this key was already used with a different payload.');
    }

    public function test_campaign_create_endpoint_is_rate_limited_per_client(): void
    {
        config()->set('services.internal_api.key', 'test-token');
        config()->set('campaign.rate_limits.create_campaign_per_minute', 1);

        $client = Client::query()->create(['name' => 'Throttle Client']);

        $payload = [
            'client_id' => $client->id,
            'name' => 'Throttle Test',
            'start_date' => now()->toIso8601String(),
        ];

        $first = $this->withToken('test-token')->postJson('/api/campaigns', $payload);
        $second = $this->withToken('test-token')->postJson('/api/campaigns', $payload);

        $first->assertCreated();
        $second->assertStatus(429)
            ->assertJsonPath('message', 'Rate limit exceeded for campaign creation.');
    }
}
