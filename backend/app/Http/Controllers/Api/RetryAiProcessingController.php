<?php

namespace App\Http\Controllers\Api;

use App\Domain\AI\Actions\RetryAiProcessing;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\Record;
use Illuminate\Http\JsonResponse;

class RetryAiProcessingController extends Controller
{
    public function store(Record $record, RetryAiProcessing $retryAiProcessing): JsonResponse
    {
        $record = $retryAiProcessing->handle($record);

        return (new RecordResource($record->loadResourceAggregates()))->response()->setStatusCode(202);
    }
}
