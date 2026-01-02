<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreZohoUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name'      => 'required|string|max:255',
            'mobile'             => 'required|string|max:15',
            'state'              => 'required|string|max:255',
            'pincode'            => 'required|string|max:10',
            'address'            => 'required|string',
            'gst'                => 'nullable|string|max:50',
            'pan'                => 'nullable|string|max:20',
            'logo'               => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',

            'owner_name'         => 'required|string|max:255',
            'email'              => 'required|email|unique:zoho_users,email',
            'password'           => 'required|string|min:6',

            'org_code'           => 'nullable|string|max:255',

            'zoho_refresh_token' => 'nullable|string',
            'zoho_client_id'     => 'nullable|string',
            'zoho_client_secret' => 'nullable|string',
            'zoho_redirect_uri'  => 'nullable|string',
            'zoho_org_id'        => 'nullable|string',
        ];
    }

    /**
     * Custom API validation response
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation errors occurred',
            'errors'  => $validator->errors(),
        ], 422));
    }
}