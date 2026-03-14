<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BannerController extends Controller
{

    /**
     * =============================
     * CREATE BANNER
     * =============================
     */
    public function createBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'  => 'required|string|max:255',
            'link'   => 'nullable|url|max:255',
            'image'  => 'required|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message'=> $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            // Upload Image
            $folder = public_path('banners');
            if (!file_exists($folder)) {
                mkdir($folder, 0755, true);
            }

            $fileName = time().'_banner.'.$request->file('image')->extension();
            $request->file('image')->move($folder, $fileName);

            $banner = Banner::create([
                'title'  => $request->title,
                'link'   => $request->link,
                'image'  => 'banners/'.$fileName,
                'status' => $request->status ?? 1
            ]);

            DB::commit();

            return response()->json([
                'status'=>true,
                'message'=>'Banner Created Successfully',
                'data'=>$banner
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            return response()->json([
                'status'=>false,
                'message'=>$e->getMessage()
            ],500);
        }
    }

    /**
     * =============================
     * UPDATE BANNER
     * =============================
     */
    public function updateBanner(Request $request, $id)
    {
        $banner = Banner::find($id);

        if(!$banner){
            return response()->json([
                'status'=>false,
                'message'=>'Banner Not Found'
            ],404);
        }

        $validator = Validator::make($request->all(), [
            'title'  => 'nullable|string|max:255',
            'link'   => 'nullable|url|max:255',
            'image'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'=>false,
                'message'=>$validator->errors()
            ],422);
        }

        if ($request->hasFile('image')) {

            $folder = public_path('banners');
            if (!file_exists($folder)) {
                mkdir($folder, 0755, true);
            }

            $fileName = time().'_banner.'.$request->file('image')->extension();
            $request->file('image')->move($folder, $fileName);

            $banner->image = 'banners/'.$fileName;
        }

        $banner->update($request->only(['title','link','status']));

        return response()->json([
            'status'=>true,
            'message'=>'Banner Updated Successfully',
            'data'=>$banner
        ]);
    }

    /**
     * =============================
     * GET ALL BANNERS (FILTERS)
     * =============================
     */
    public function listBanners(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'      => 'nullable|integer',
            'status'  => 'nullable|boolean',
            'search'  => 'nullable|string',
            'deleted' => 'nullable|in:1,2',
            'per_page'=> 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'=>false,
                'message'=>$validator->errors()
            ],422);
        }

        $query = Banner::query();

        if ($request->filled('id')) {
            $banner = $query->find($request->id);

            if (!$banner) {
                return response()->json([
                    'status'=>false,
                    'message'=>'Banner Not Found'
                ],404);
            }

            return response()->json([
                'status'=>true,
                'data'=>$banner
            ]);
        }

        if ($request->filled('status')) {
            $query->where('status',$request->status);
        }

        if ($request->filled('search')) {
            $query->where('title','like','%'.$request->search.'%');
        }

        if ($request->filled('deleted')) {
            if ($request->deleted == 1) {
                $query->onlyTrashed();
            } elseif ($request->deleted == 2) {
                $query->withTrashed();
            }
        }

        $perPage = $request->per_page ?? 10;

        $banners = $query->orderBy('id','desc')->paginate($perPage);

        return response()->json([
            'status'=>true,
            'message'=>'Banner List Retrieved Successfully',
            'data'=>$banners
        ]);
    }

    /**
     * =============================
     * CHANGE STATUS (Common API)
     * =============================
     */
    public function changeStatus(Request $request, $id)
    {
        $banner = Banner::find($id);

        if(!$banner){
            return response()->json([
                'status'=>false,
                'message'=>'Banner Not Found'
            ],404);
        }

        $validator = Validator::make($request->all(), [
            'status'=>'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'=>false,
                'message'=>$validator->errors()
            ],422);
        }

        $banner->update(['status'=>$request->status]);

        return response()->json([
            'status'=>true,
            'message'=>'Banner Status Updated',
            'data'=>$banner
        ]);
    }

    /**
     * SOFT DELETE
     */
    public function deleteBanner($id)
    {
        $banner = Banner::find($id);

        if(!$banner){
            return response()->json([
                'status'=>false,
                'message'=>'Banner Not Found'
            ],404);
        }

        $banner->delete();

        return response()->json([
            'status'=>true,
            'message'=>'Banner Deleted Successfully'
        ]);
    }
}