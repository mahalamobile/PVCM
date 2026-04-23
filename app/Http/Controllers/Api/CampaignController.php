<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CampaignController extends Controller
{
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::query()->create($request->validated());

        $campaign->load('client');

        return (new CampaignResource($campaign))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
