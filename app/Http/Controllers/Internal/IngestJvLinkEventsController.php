<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\JvLinkIngestException;
use App\Http\Requests\IngestJvLinkEventsRequest;
use App\Services\IngestJvLinkEvents;
use Illuminate\Http\JsonResponse;

class IngestJvLinkEventsController
{
    public function __invoke(IngestJvLinkEventsRequest $request, IngestJvLinkEvents $ingest): JsonResponse
    {
        try {
            return response()->json($ingest->ingest($request->validated()));
        } catch (JvLinkIngestException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_category' => $exception->category,
                'retryable' => $exception->retryable,
            ], 409);
        }
    }
}
