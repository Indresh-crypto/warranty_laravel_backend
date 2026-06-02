<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WLead;
use App\Models\WLeadRemark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WLeadRemarkController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | ADD REMARK
    |--------------------------------------------------------------------------
    */

    public function addRemark(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'lead_id'        => 'required|exists:w_leads,id',
            'remark'         => 'required|string',
            'followup_date'  => 'nullable|date',
            'follow_up_by'   => 'nullable|exists:company_employee,id',
            'status'         => 'nullable|string',

        ]);

        if ($validator->fails()) {

            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE REMARK
        |--------------------------------------------------------------------------
        */

        $remark = WLeadRemark::create([

            'lead_id'       => $request->lead_id,
            'remark'        => $request->remark,
            'followup_date' => $request->followup_date,
            'follow_up_by'  => $request->follow_up_by,
            'status'        => $request->status,
            'created_by'    => auth()->id(),

        ]);

        /*
        |--------------------------------------------------------------------------
        | UPDATE LEAD FOLLOWUP
        |--------------------------------------------------------------------------
        */

        $lead = WLead::find($request->lead_id);

        $lead->update([

            'followup_date' => $request->followup_date,
            'follow_up_by'  => $request->follow_up_by,

        ]);

        return response()->json([

            'status'  => true,
            'message' => 'Remark added successfully',
            'data'    => $remark->load('followupUser', 'createdUser')

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET LEAD REMARKS
    |--------------------------------------------------------------------------
    */

    public function getRemarks($lead_id)
    {
        $lead = WLead::with([
            'remarks.followupUser',
            'remarks.createdUser'
        ])->find($lead_id);

        if (!$lead) {

            return response()->json([
                'status' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        return response()->json([

            'status' => true,
            'data'   => $lead

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE REMARK
    |--------------------------------------------------------------------------
    */

    public function deleteRemark($id)
    {
        $remark = WLeadRemark::find($id);

        if (!$remark) {

            return response()->json([
                'status' => false,
                'message' => 'Remark not found'
            ], 404);
        }

        $remark->delete();

        return response()->json([

            'status' => true,
            'message' => 'Remark deleted successfully'

        ]);
    }
}