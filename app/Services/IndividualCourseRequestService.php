<?php

namespace App\Services;

use App\Models\IndividualCourseRequest;
use App\Models\PlacementTest;
use App\Models\User;
use App\Repositories\IndividualCourseRequestRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;


use App\Models\Language;

class IndividualCourseRequestService
{
    protected $repo;

    public function __construct(IndividualCourseRequestRepository $repo)
    {
        $this->repo = $repo;
    }


    //need an update that should send a notification to secretarya
    public function handleCourseRequest($languageId, $user, $description): JsonResponse
    {
        $language = Language::find($languageId);
        if (!$language) {
            return response()->json([
                'message' => 'Language Not Found'
            ], 404);
        }

        $lastTest = PlacementTest::where('GuestId', $user->id)
            ->where('LanguageId', $languageId)
            ->where('Status', 'Completed')
            ->latest()
            ->first();


        if (!$lastTest) {
            return response()->json([
                'message' => 'Sorry, You should take the placement test for this language or your status of test is pending',
                'language' => $language,
            ], 403);
        }

        if ($lastTest->created_at->lt(Carbon::now()->subDays(30))) {
            return response()->json([
                'message' => 'Your placement test for this language is older than 30 days. Please retake the test to assess your level accurately.',
                'language' => $language,
                'old_test' => $lastTest,
            ], 403);
        }

        $request = $this->repo->createRequest($user->id, $languageId, $description);

        return response()->json([
            'message' => 'Send The Request Successfully',
            'request' => $request,
            'user_info' => $user,
            'language' => $language,
            'placement_test' => $lastTest,
        ]);
    }


    public function respondToRequest($requestId, $responseText)
    {
        $request = $this->repo->find($requestId);

        if (!$request) {
            return response()->json(['message' => 'The request does not exist'], 404);
        }

        $request->secretarya_response = $responseText;
        $request->status = 'Done';
        $request->save();

        $request->load(['user', 'language']);

        return response()->json([
            'message' => 'The request has been successfully modified and its status has been updated to Done',
            'request' => $request,
        ]);
    }

    public function getUserRequests($user)
    {
        $query = IndividualCourseRequest::with(['user', 'language'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if (request()->has('status')) {
            $query->where('status', request('status'));
        }

        return $query->get();
    }


    public function getAllRequests()
    {
        $query = IndividualCourseRequest::with(['user.roles', 'language'])
            ->orderBy('created_at', 'desc');

        if (request()->has('status')) {
            $query->where('status', request('status'));
        }

        return $query->get();
    }


    public function deleteRequest($requestId)
    {
        $request = $this->repo->find($requestId);

        if (!$request) {
            return response()->json(['message' => 'This request was not found or may have already been deleted.'], 404);
        }

        // Check if the authenticated user is the owner of the request
        if ($request->user_id !== Auth::id()) {
            return response()->json(['message' => 'You do not have permission to delete this request because you are not its creator.'], 403);
        }

        $request->delete();

        return response()->json(['message' => 'The request has been deleted successfully.']);
    }

    public function updateRequest($requestId, $newLanguageId = null, $description = null)
    {
        $request = $this->repo->find($requestId);

        if (!$request) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($request->user_id !== Auth::id()) {
            return response()->json(['message' => 'You are not the owner of this request.'], 403);
        }

        if ($request->status !== 'Pending') {
            return response()->json(['message' => 'Cannot update this request because it is already marked as Done.'], 403);
        }

        if ($newLanguageId) {
            $language = Language::find($newLanguageId);
            if (!$language) {
                return response()->json(['message' => 'Language not found.'], 404);
            }
            $request->language_id = $newLanguageId;
        }

        if (!is_null($description)) {
            $request->description = $description;
        }

        $request->save();

        return response()->json([
            'message' => 'Request updated successfully.',
            'request' => $request
        ]);
    }
}
