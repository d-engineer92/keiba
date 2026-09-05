<?php

namespace App\Http\Controllers\Internal;

use App\Http\Requests\IngestSchedulesRequest;
use App\Services\IngestJvLinkSchedules;
use Illuminate\Http\JsonResponse;

class IngestSchedulesController
{
    public function __invoke(IngestSchedulesRequest $request, IngestJvLinkSchedules $ingest): JsonResponse
    {
        return response()->json($ingest->ingest($request->validated()));
    }
}
