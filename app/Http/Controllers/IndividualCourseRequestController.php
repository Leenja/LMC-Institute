<?php

namespace App\Http\Controllers;

use App\Services\IndividualCourseRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class IndividualCourseRequestController extends Controller
{
    protected $service;

    public function __construct(IndividualCourseRequestService $service)
    {
        $this->service = $service;
    }


    public function requestCourse(Request $request,$languageId): JsonResponse
    {
        $request->validate(['description' => 'required|string']);
        return $this->service->handleCourseRequest($languageId, auth()->user(),$request->description);
    }

    public function respondToRequestIndividualCourse(Request $request, $id)
    {
        $request->validate(['response' => 'required|string']);

        return $this->service->respondToRequest($id, $request->response);
    }

    public function myRequestsForIndividualCourse(Request $request)
    {
        $requests = $this->service->getUserRequests($request->user());

        return response()->json($requests);
    }

    public function showAllIndividualRequest(): JsonResponse
    {
        $requests = $this->service->getAllRequests();

        return response()->json($requests);
    }

    public function deleteIndividualRequest($id): JsonResponse
    {
        return $this->service->deleteRequest($id);
    }

    public function updateIndividualRequest(Request $request, $id): JsonResponse
    {
        $request->validate([
            'language_id' => 'sometimes|exists:languages,id',
            'description' => 'sometimes|string',
        ]);

        if (!$request->hasAny(['language_id', 'description'])) {
            return response()->json(['message' => 'No data provided to update.'], 422);
        }

        return $this->service->updateRequest(
            $id,
            $request->input('language_id'),
            $request->input('description')
        );
    }

}
