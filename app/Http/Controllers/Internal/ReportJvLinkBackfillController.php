<?php

namespace App\Http\Controllers\Internal;

use App\Http\Requests\ReportJvLinkBackfillRequest;
use App\Services\ReportJvLinkBackfill;
use Illuminate\Http\JsonResponse;

class ReportJvLinkBackfillController
{
    public function __invoke(ReportJvLinkBackfillRequest $request, ReportJvLinkBackfill $report): JsonResponse
    {
        return response()->json($report->store($request->validated()));
    }
}
