# Personalized Video Campaign Manager (PVCM)

Production-grade Laravel API for managing personalized video campaigns with async ingestion, duplicate handling, analytics reporting, and observability support.

## Technology Stack

- PHP 8.3
- Laravel 13.x (compatible with 12+ requirement)
- MySQL 8.4
- Laravel Queues (database driver)
- Docker + Docker Compose
- Optional Prometheus + Grafana monitoring profile

## Project Structure

```
app/
  Actions/HandleCampaignDataChunk.php
  Console/Commands/GenerateCampaignAnalyticsCommand.php
  Http/
    Controllers/Api/
    Middleware/EnsureBearerTokenMatches.php
    Requests/
    Resources/
  Jobs/IngestCampaignDataChunkJob.php
  Models/
database/migrations/
docker/
  monitoring/
  nginx/
  php/
routes/
  api.php
  web.php
```

## Data Model

- `clients`
  - `id`, `name`, `external_reference`, timestamps
- `campaigns`
  - `id`, `client_id`, `name`, `start_date`, `end_date`, timestamps
  - indexed for date-range lookups
- `campaign_data`
  - `id`, `campaign_id`, `user_id`, `video_url`, `custom_fields` (JSON), `ingested_at`, timestamps
  - unique index: `(campaign_id, user_id)` for duplicate detection
- `campaign_data_duplicates`
  - audit table for duplicate attempts (`strategy`, `incoming_payload`, `existing_payload`, `resolution`)

## Security (Bearer Auth + Doppler)

All API routes in `routes/api.php` and `/metrics` require a bearer token.

- Header: `Authorization: Bearer <API_BEARER_TOKEN>`
- Middleware: `EnsureBearerTokenMatches`
- Config source: `services.internal_api.key` (`API_BEARER_TOKEN`)

### Doppler Integration

Store the secret in Doppler:

```bash
doppler secrets set API_BEARER_TOKEN="replace-with-strong-token"
```

Run commands with injected secrets:

```bash
doppler run -- docker compose up -d --build
```

For non-Doppler local runs, add `API_BEARER_TOKEN` to `.env`.

## Rate Limiting / Throttling

Per-client throttling is enforced with named Laravel rate limiters:

- `POST /api/campaigns` -> limiter `client-campaign-create`
- `POST /api/campaigns/{campaign_id}/data` -> limiter `client-ingestion`

Both use the client identity as the limiter key (fallback to IP when client context is unavailable).

Environment variables:

- `RATE_LIMIT_CREATE_CAMPAIGN_PER_MINUTE` (default `60`)
- `RATE_LIMIT_INGEST_DATA_PER_MINUTE` (default `120`)

When limits are exceeded, API returns `429 Too Many Requests`.

## API Endpoints

Base URL (Docker): `http://localhost:8080`

Interactive OpenAPI/Swagger UI: `http://localhost:8080/docs`
Raw OpenAPI spec: `http://localhost:8080/docs/openapi.yaml`

### 1) Create Campaign

`POST /api/campaigns`

Request:

```json
{
  "client_id": 1,
  "name": "Spring Video Promo",
  "start_date": "2026-04-23T09:00:00Z",
  "end_date": "2026-05-30T23:59:59Z"
}
```

Response: `201 Created`

```json
{
  "data": {
    "id": 10,
    "client_id": 1,
    "name": "Spring Video Promo",
    "start_date": "2026-04-23T09:00:00+00:00",
    "end_date": "2026-05-30T23:59:59+00:00",
    "created_at": "2026-04-23T09:10:00+00:00",
    "updated_at": "2026-04-23T09:10:00+00:00"
  }
}
```

### 2) Add Campaign User Data (Async)

`POST /api/campaigns/{campaign_id}/data`

Accepts either:
- raw array body, or
- `{ "data": [ ... ] }`

Each data object:
- `user_id` (required)
- `video_url` (required)
- `custom_fields` (optional object, stored in JSON column)

Request:

```json
{
  "data": [
    {
      "user_id": "user-1001",
      "video_url": "https://cdn.example.com/pv/user-1001.mp4",
      "custom_fields": {
        "first_name": "Sam",
        "segment": "gold",
        "region": "ZA"
      }
    }
  ]
}
```

Response: `202 Accepted`

```json
{
  "message": "Campaign data accepted for processing.",
  "campaign_id": 10,
  "received_records": 1,
  "chunk_size": 500,
  "duplicate_strategy": "update"
}
```

## Duplicate Handling Strategy

Configured via `CAMPAIGN_DUPLICATE_STRATEGY`:

- `update` (default): latest payload overwrites current record
- `reject`: keep original, log duplicate attempt
- `merge`: merge `custom_fields` (incoming keys overwrite existing keys), update `video_url`

Each duplicate attempt is recorded in `campaign_data_duplicates` for visibility and reporting.

## Idempotency Keys (Ingestion Safety)

`POST /api/campaigns/{campaign_id}/data` requires an idempotency key header:

- header name default: `Idempotency-Key`
- configurable with `CAMPAIGN_IDEMPOTENCY_HEADER`
- retention window: `CAMPAIGN_IDEMPOTENCY_TTL_HOURS` (default `48`)

Behavior:

- first request with a new key + payload: accepted (`202`) and queued
- retry with same key + same payload: returns stored accepted response, no duplicate queue dispatch
- same key + different payload: rejected with `409 Conflict`

Cleanup:

- command: `php artisan campaigns:idempotency:purge`
- automatically scheduled hourly via scheduler

## Background Job Flow

1. API validates payload and immediately returns `202`.
2. Payload is chunked (`CAMPAIGN_INGEST_CHUNK_SIZE`, default `500`).
3. One `IngestCampaignDataChunkJob` is dispatched per chunk.
4. Worker processes chunk:
   - detects duplicates by `(campaign_id, user_id)`
   - applies configured strategy
   - writes duplicate logs

Queue command used in Docker:

```bash
php artisan queue:work --queue=campaign-data,default --tries=3 --backoff=3 --max-time=3600
```

## Redis Usage Status

Redis is configured as an available option but is **not currently active by default** in this project.

- active queue backend: `database` (`QUEUE_CONNECTION=database`)
- active cache backend: `database` (`CACHE_STORE=database`)
- Redis can be enabled later for higher throughput workloads.

## Analytics Command

Command:

```bash
php artisan campaigns:analytics
```

Options:

- `--campaign_id=10`
- `--from="2026-04-01 00:00:00"`
- `--to="2026-04-30 23:59:59"`
- `--format=json` (default is `table`)

Example:

```bash
php artisan campaigns:analytics --format=json
```

Outputs totals for campaigns, active campaigns, ingested videos, duplicates, and per-campaign row counts.

## Local Setup (Docker)

### Prerequisites

- Docker Desktop (engine running)
- Optional: Doppler CLI (if using secret injection)

### 1) Configure Environment

```bash
cp .env.example .env
```

Set at minimum:

- `API_BEARER_TOKEN`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- optional `CAMPAIGN_DUPLICATE_STRATEGY`

### 2) Build and Start

```bash
docker compose up -d --build
```

Services:

- API: `http://localhost:8080`
- MySQL host port: `33061`
- Queue worker and scheduler run as separate containers

### 3) Verify

```bash
curl http://localhost:8080/up
```

Should return Laravel health response.

### 4) Seed Demo Client

```bash
docker compose exec app php artisan db:seed
```

Creates a `Demo Client` if missing.

## Testing API Quickly

```bash
curl -X POST http://localhost:8080/api/campaigns \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${API_BEARER_TOKEN}" \
  -d '{
    "client_id": 1,
    "name": "Launch Campaign",
    "start_date": "2026-04-23T09:00:00Z"
  }'
```

## Monitoring with Prometheus + Grafana

The stack includes an optional `monitoring` profile:

```bash
docker compose --profile monitoring up -d
```

- Prometheus: `http://localhost:9090`
- Grafana: `http://localhost:3000`

### Metrics Endpoint

- `GET /metrics` (Prometheus text format, bearer-auth protected)
- Current metrics:
  - `pvcm_campaigns_total`
  - `pvcm_campaign_data_total`
  - `pvcm_campaign_duplicates_total`
  - `pvcm_campaigns_active`
  - `pvcm_jobs_pending`
  - `pvcm_jobs_campaign_data_pending`

### Prometheus Config

`docker/monitoring/prometheus.yml` includes:
- `/up` scrape for app health
- `/metrics` scrape for PVCM KPIs

Before running monitoring, set the bearer token in `prometheus.yml`:

- replace `CHANGE_ME_WITH_API_BEARER_TOKEN`

### Recommended Grafana Dashboards

- API health and uptime (`up` query)
- queue backlog trend (`pvcm_jobs_campaign_data_pending`)
- duplicate rate (`increase(pvcm_campaign_duplicates_total[5m])`)
- throughput (`increase(pvcm_campaign_data_total[5m])`)
- active campaigns (`pvcm_campaigns_active`)

## Production Hardening Checklist

- use long random `API_BEARER_TOKEN` from secret manager (Doppler)
- terminate TLS at ingress/load balancer
- run multiple queue workers for high ingestion throughput
- configure Horizon/Redis if queue volume grows beyond DB queue limits
- set up centralized logs and alerts
- use managed MySQL with backups and PITR
- configure WAF/rate limiting at edge

## Notes

- Endpoint responses use proper HTTP codes (`201`, `202`, `401`, `422`).
- Validation errors are returned in Laravel standard JSON format.
- The ingestion endpoint is optimized with chunked jobs and bulk upsert patterns.
