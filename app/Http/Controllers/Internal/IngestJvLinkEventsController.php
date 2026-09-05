<?php

namespace App\Http\Controllers\Internal;

use App\Http\Requests\IngestJvLinkEventsRequest;
use App\Services\IngestJvLinkEvents;
use Illuminate\Http\JsonResponse;

class IngestJvLinkEventsController
{
    public function __invoke(IngestJvLinkEventsRequest $request, IngestJvLinkEvents $ingest): JsonResponse
    {
        return response()->json($ingest->ingest($request->validated()));
    }
}
