<?php

namespace App\Http\Controllers\Api;

use App\Domain\Documents\ReportWorkbookBuilder;
use App\Domain\Reports\ReportFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexReportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportExcelController extends Controller
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function show(IndexReportRequest $request, ReportWorkbookBuilder $builder): BinaryFileResponse|JsonResponse
    {
        // `page` and `per_page` are validated by the shared request but never applied: the export is always complete.
        $filters = ReportFilters::fromValidated($request->validated());

        try {
            $workbookPath = $builder->build($filters);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The Excel report could not be generated.'], 500);
        }

        $response = response()
            ->download($workbookPath, $builder->fileName($filters), ['Content-Type' => self::XLSX_MIME])
            ->deleteFileAfterSend(true);

        // BinaryFileResponse defaults to public caching; a generated report must never be cached.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
