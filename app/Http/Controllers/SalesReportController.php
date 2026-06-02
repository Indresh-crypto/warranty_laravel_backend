<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SalesReportService;

class SalesReportController extends Controller
{
    protected $service;

    public function __construct(SalesReportService $service)
    {
        $this->service = $service;
    }

    public function report(Request $request)
    {
        $data = $this->service->getReport($request->all());

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function compare()
    {
        return response()->json([
            'status' => true,
            'data' => $this->service->compareToday()
        ]);
    }
}