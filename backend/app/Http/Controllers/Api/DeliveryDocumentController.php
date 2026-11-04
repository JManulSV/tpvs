<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Documents\DeliveryDocumentBuilder;
use App\Domain\Documents\DeliveryDocumentEligibility;
use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deliveries\GenerateDeliveryDocumentRequest;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeliveryDocumentController extends Controller
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function store(
        GenerateDeliveryDocumentRequest $request,
        Delivery $delivery,
        DeliveryDocumentEligibility $eligibility,
        DeliveryDocumentBuilder $builder,
        DeliveryHistoryService $history,
    ): BinaryFileResponse|JsonResponse {
        $record = Record::query()->findOrFail($delivery->record_id);

        Gate::authorize('generateDeliveryDocument', $record);

        $input = $request->validated();
        $documentPath = null;

        try {
            DB::transaction(function () use ($request, $delivery, $eligibility, $builder, $history, $input, &$documentPath, &$record): void {
                $record = Record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                $locked = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

                $imagePath = $eligibility->check(
                    $locked,
                    $record,
                    $input['document_return_amount'],
                    filter_var($input['customer_will_give_change'], FILTER_VALIDATE_BOOL),
                );
                $documentPath = $builder->build($locked, $imagePath);

                $history->writeDocumentGenerated($locked, $request->user()->getKey());
            });
        } catch (DocumentGenerationBlockedException $blocked) {
            return response()->json([
                'message' => $blocked->getMessage(),
                'code' => 'DOCUMENT_GENERATION_BLOCKED',
                'details' => $blocked->responseDetails(),
            ], 409);
        } catch (ValidationException $validationException) {
            throw $validationException;
        } catch (\Throwable $exception) {
            report($exception);

            if ($documentPath !== null) {
                @unlink($documentPath);
            }

            return response()->json(['message' => 'The delivery document could not be generated.'], 500);
        }

        $response = response()
            ->download($documentPath, $this->fileName($record), ['Content-Type' => self::DOCX_MIME])
            ->deleteFileAfterSend(true);

        // BinaryFileResponse defaults to public caching; a generated receipt must never be cached.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function fileName(Record $record): string
    {
        return sprintf('entrega-registro-%d-semana-%d-%d.docx', $record->getKey(), $record->week, $record->year);
    }
}
