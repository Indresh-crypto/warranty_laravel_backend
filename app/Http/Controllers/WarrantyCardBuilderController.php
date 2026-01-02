<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WAppCard;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\WclaimResource;
use App\Models\Wclaim;
use App\Models\WDevice;
use App\Models\OrgUser;
use Illuminate\Support\Facades\DB;


class WarrantyCardBuilderController extends Controller
{
    public function getDashboardCards(Request $request)
    {
       $data = WAppCard::get()->map(function ($item) {
       return  [
            'id'         => $item->id,
            'title'      => $item->title,
            'image'    => $item->image,
            'card_value' => 0, 
        ];
    });
      return response()->json($data, Response::HTTP_OK);
    }

    public function getClaimList(Request $request)
    {
        $claims = Wclaim::with(['customer', 'product', 'device'])
                        ->latest()
                        ->paginate(10); 

        return WclaimResource::collection($claims);
    }

    public function addRemark(Request $request, $id)
    {
        $request->validate([
            'remark' => 'required|string',
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $claim = Wclaim::findOrFail($id);

        $remark = $claim->remarks()->create([
            'user_id' => auth()->id(),
            'remark' => $request->remark,
            'status' => $request->status,
        ]);

        return response()->json(['message' => 'Remark added', 'data' => $remark]);
    }

    public function getRemarks($id)
    {
        $claim = Wclaim::with(['remarks.user'])->findOrFail($id);

        return response()->json([
            'claim_id' => $claim->id,
            'remarks' => $claim->remarks->map(function ($r) {
                return [
                    'id' => $r->id,
                    'remark' => $r->remark,
                    'status' => $r->status,
                    'user' => $r->user?->name,
                    'created_at' => $r->created_at->toDateTimeString(),
                ];
            })
        ]);
    }

  public function monthlySales(Request $request)
  {
      $months = [];
      $totals = [];

      $retailerId = $request->retailer_id;

      for ($i = 5; $i >= 0; $i--) {
          $start = Carbon::now()->subMonths($i)->startOfMonth();
          $end = Carbon::now()->subMonths($i)->endOfMonth();

          $query = WDevice::whereBetween('created_at', [$start, $end]);

          if ($retailerId) {
              $query->where('retailer_id', $retailerId);
          }

          $count = $query->count();

          $months[] = $start->format('M'); // Jan, Feb, etc.
          $totals[] = $count;
      }

      return response()->json([
          'months' => $months,
          'totals' => $totals,
      ]);
  }

    public function promoterWiseSales(Request $request)
    {
        $retailerId = $request->retailer_id;

        $query = WDevice::query()
            ->select('promoter_id', DB::raw('SUM(device_price) as total_amount'))
            ->whereNotNull('promoter_id');

        if ($retailerId) {
            $query->where('retailer_id', $retailerId);
        }
        $query->groupBy('promoter_id');
        $results = $query->get();

        $data = $results->map(function ($item) {
            $promoter = OrgUser::find($item->promoter_id);
            return [
                'promoter_id' => $item->promoter_id,
                'promoter_name' => $promoter?->owner_name ?? 'Unknown',
                'total_amount' => (float) $item->total_amount,
            ];
        });

        $grandTotal = $results->sum('total_amount');

        return response()->json([
            'retailer_id' => $retailerId,
            'data' => $data,
            'total_earning' => $grandTotal,
        ]);
    }

public function promoterWiseSalesList(Request $request)
{
    $retailerId = $request->retailer_id;
    $perPage = $request->input('per_page', 10); // default 10 per page

    $query = WDevice::query()
        ->whereNotNull('promoter_id');

    if ($retailerId) {
        $query->where('retailer_id', $retailerId);
    }

    // Paginate the devices
    $paginatedDevices = $query->paginate($perPage);

    $grouped = $paginatedDevices->getCollection()->groupBy('promoter_id');

    $data = $grouped->map(function ($group, $promoterId) {
        $promoter = OrgUser::find($promoterId);

        return [
            'promoter_id'   => $promoterId,
            'promoter_name' => $promoter?->owner_name ?? 'Unknown',
            'total_amount'  => (float) $group->sum('device_price'),
            'total_sales'   => $group->count(),
            'devices'       => $group->map(function ($device) {
                return [
                    'id'              => $device->id,
                    'name'            => $device->name,
                    'imei1'           => $device->imei1,
                    'imei2'           => $device->imei2,
                    'serial'          => $device->serial,
                    'product_name'    => $device->product_name,
                    'brand_name'      => $device->brand_name,
                    'category_name'   => $device->category_name,
                    'available_claim' => $device->available_claim,
                    'expiry_date'     => $device->expiry_date,
                    'invoice_id'      => $device->invoice_id,
                    'w_customer_id'   => $device->w_customer_id,
                    'document_url'    => $device->document_url,
                    'retailer_id'     => $device->retailer_id,
                    'is_approved'     => $device->is_approved,
                    'created_at'      => $device->created_at,
                    'updated_at'      => $device->updated_at,
                    'model'           => $device->model,
                    'promoter_id'     => $device->promoter_id,
                    'device_price'    => $device->device_price,
                ];
            })->values(),
        ];
    })->values();

    $totalEarning = $paginatedDevices->getCollection()->sum('device_price');
    $totalSales = $paginatedDevices->getCollection()->count();

    return response()->json([
        'current_page'   => $paginatedDevices->currentPage(),
        'last_page'      => $paginatedDevices->lastPage(),
        'per_page'       => $paginatedDevices->perPage(),
        'total_records'  => $paginatedDevices->total(),
        'total_earning'  => $totalEarning,
        'total_sales'    => $totalSales,
        'data'           => $data,
    ]);
}
}