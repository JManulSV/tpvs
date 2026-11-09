<?php

namespace App\Http\Controllers\Api;

use App\Domain\Dashboard\DashboardQuery;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardActivityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, DashboardQuery $dashboard): JsonResponse
    {
        $timezone = (string) config('tpvs.business_timezone');
        $now = now($timezone);
        $year = (int) $now->year;
        $month = (int) $now->month;

        $data = [
            'period' => ['year' => $year, 'month' => $month, 'timezone' => $timezone],
        ];

        if ($request->user()->role === UserRole::EDITOR) {
            $data['status_counts'] = $dashboard->statusCounts($year, $month);
        }

        $data['month_summary'] = $dashboard->monthSummary($year, $month);
        $data['recent_activity'] = DashboardActivityResource::collection($dashboard->recentActivity())
            ->resolve($request);

        return response()->json(['data' => $data]);
    }
}
