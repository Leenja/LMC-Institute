<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Services\ComplaintService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class ComplaintController extends Controller
{
    protected $ComplaintService;

    public function __construct(ComplaintService $ComplaintService)
    {
        $this->ComplaintService = $ComplaintService;
    }


    //Teacher------------------------------------------------------------
    public function submitComplaint(Request $request)
    {
     $user1 = Auth::user();

     if (!$user1) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthenticated'
        ], 401);
     }

     $validator = Validator::make($request->all(), [
        'Subject' => 'required|string',
     ]);

     if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'errors' => $validator->errors()
        ], 422);
     }

     $complaint = $this->ComplaintService->createTeacherComplaint(
        $user1,
        $request->Subject
     );

     return response()->json([
        'status' => 'success',
        'data' => $complaint,
        'message' => 'Complaint created successfully'
     ], 201);
    }

    public function editComplaint(Request $request, $complaintId)
    {
     $user = Auth::user();

     if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthenticated'
        ], 401);
     }

     $response = $this->ComplaintService->editComplaint($request, $complaintId, $user);

     return response()->json($response, $response['status_code']);
    }

    public function deleteComplaint($id)
    {
     $user = Auth::user();

     if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthenticated'
        ], 401);
     }

     $response = $this->ComplaintService->deleteComplaint($id, $user->id);

     return response()->json($response, $response['status_code']);
    }

    //SuperAdmin
    public function showComplaint($id)
    {
     $response = $this->ComplaintService->getComplaintDetails($id);

     return response()->json($response, $response['status_code']);
    }

    public function showAllComplaint()
    {
     $response = $this->ComplaintService->getAllComplaints();

     return response()->json($response, $response['status_code']);
    }

    public function showTeacherComplaints($teacherId)
    {
     $response = $this->ComplaintService->getTeacherComplaints($teacherId);

    return response()->json($response, /*$response['status_code']*/);
    }

    public function showPendingComplaints()
    {
     $response = $this->ComplaintService->getComplaintsByStatus('Pending');

     return response()->json($response, /*$response['status_code']*/);
    }

    public function showSolvedComplaints()
    {
     $response = $this->ComplaintService->getComplaintsByStatus('Solved');

     return response()->json($response, /*$response['status_code']*/);
    }

    public function checkComplaint($complaintId)
    {
     $response = $this->ComplaintService->markComplaintAsSolved($complaintId);

     return response()->json($response, $response['status_code']);
    }

    //Teacher //show the own complaint for the teacher
    public function showTeacherOwnComplaints()
    {
     $user = auth()->user();
     $response = $this->ComplaintService->getTeacherownComplaints($user);

     return response()->json($response, $response['status_code']);
    }


    //Show the own solved complaint
    public function showSolvedComplaintsTeacher()
    {
     $user = Auth::user();
     $response = $this->ComplaintService->getTeacherComplaintsByStatus($user ,'Solved');

     return response()->json($response, $response['status_code']);
    }


    //Show the own pending complaint
    public function showPendingComplaintsTeacher()
    {
     $user = Auth::user();
     $response = $this->ComplaintService->getTeacherComplaintsByStatus($user , 'Pending');

     return response()->json($response, $response['status_code']);
    }

}
