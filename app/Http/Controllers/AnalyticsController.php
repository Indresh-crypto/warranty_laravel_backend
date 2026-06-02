<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AnalyticsService;

class AnalyticsController extends Controller
{
    protected $service;

    public function __construct(AnalyticsService $service)
    {
        $this->service = $service;
    }

    public function dashboard(Request $request)
    {
        $data = $this->service->dashboard($request->all());

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}