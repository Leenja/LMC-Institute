<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageController extends Controller
{
    protected $pageService;

    public function __construct(PageService $pageService)
    {
        $this->pageService = $pageService;
    }

    public function addPage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_contact'  => 'nullable|boolean',
            'photo'       => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return $this->pageService->addPage($request, $validator->validated());
    }

    public function showPage($id)
    {
        return $this->pageService->showPage($id);
    }

    public function showAllPage(Request $request)
    {
        return $this->pageService->showAllPages($request);
    }

    public function updatePage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'is_contact'  => 'nullable|boolean',
            'photo'       => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        if (empty($validated) /*&& !$request->hasFile('photo')*/) {
            return response()->json(['message' => 'No data provided for update.'], 400);
        }

        return $this->pageService->updatePage($request, $id, $validator->validated());
    }

    public function destroyPage($id)
    {
        return $this->pageService->deletePage($id);
    }
}
