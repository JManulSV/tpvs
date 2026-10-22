<?php

namespace Tests\Unit;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Http\Resources\RecordResource;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use Tests\TestCase;

class RecordResourceAiProcessingTest extends TestCase
{
    public function test_ai_resource_exposes_normalized_result_but_never_raw_response_or_internal_error(): void
    {
        $processing = new AiProcessing([
            'provider' => 'ollama',
            'model' => 'qwen2.5vl:3b',
            'status' => AiProcessingStatus::COMPLETED,
            'result' => [
                'raw_response' => 'private raw response',
                'normalized_extraction' => ['record' => ['year' => ['value' => 2026, 'confidence' => 0.95]]],
                'issues' => [['path' => 'record.week', 'code' => 'LOW_CONFIDENCE']],
                'unconvertible_numeric_count' => 0,
            ],
            'error_message' => '/private/path/stacktrace',
        ]);
        $processing->setAttribute('id', 7);

        $image = new Image([
            'original_file_name' => 'statement.png',
            'mime_type' => 'image/png',
            'file_size' => 42,
        ]);
        $image->setAttribute('id', 5);
        $image->setRelation('aiProcessing', $processing);

        $record = new Record([
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::NEEDS_REVIEW,
        ]);
        $record->setAttribute('id', 3);
        $record->setRelation('image', $image);

        $resource = (new RecordResource($record))->resolve();

        $this->assertSame('COMPLETED', $resource['ai_processing']['status']);
        $this->assertSame(2026, $resource['ai_processing']['normalized_extraction']['record']['year']['value']);
        $this->assertSame(0, $resource['ai_processing']['unconvertible_numeric_count']);
        $this->assertArrayNotHasKey('raw_response', $resource['ai_processing']);
        $this->assertArrayNotHasKey('error_message', $resource['ai_processing']);
        $this->assertStringNotContainsString('private raw response', json_encode($resource, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('/private/path/stacktrace', json_encode($resource, JSON_THROW_ON_ERROR));
    }
}
