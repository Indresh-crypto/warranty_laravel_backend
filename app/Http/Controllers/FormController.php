<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Form;
use App\Models\FormField;
use DB;

class FormController extends Controller
{
    /**
     * Create new form with fields
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'fields' => 'required|array'
        ]);

        DB::beginTransaction();

        try {
            $form = Form::create([
                'title' => $request->title,
                'description' => $request->description
            ]);

            foreach ($request->fields as $index => $field) {
                FormField::create([
                    'form_id' => $form->id,
                    'label' => $field['label'],
                    'field_type' => $field['field_type'],
                    'is_required' => $field['is_required'] ?? 0,
                    'options' => $field['options'] ?? null,
                    'sort_order' => $index
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'form' => $form->load('fields')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all forms
     */
    public function index()
    {
        return response()->json(
            Form::with('fields')->get()
        );
    }

    /**
     * Get single form
     */
    public function show($id)
    {
        $form = Form::with('fields')->findOrFail($id);
        return response()->json($form);
    }

    /**
     * Update form & fields
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $form = Form::findOrFail($id);
            $form->update([
                'title' => $request->title,
                'description' => $request->description
            ]);

            // Delete old fields
            FormField::where('form_id', $form->id)->delete();

            // Insert new fields
            foreach ($request->fields as $index => $field) {
                FormField::create([
                    'form_id' => $form->id,
                    'label' => $field['label'],
                    'field_type' => $field['field_type'],
                    'is_required' => $field['is_required'] ?? 0,
                    'options' => $field['options'] ?? null,
                    'sort_order' => $index
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'form' => $form->load('fields')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete form
     */
    public function destroy($id)
    {
        Form::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}