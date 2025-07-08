<?php

namespace App\Services;

use App\Repositories\ComplaintRepository;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class ComplaintService
{
    private $ComplaintRepository;

    public function __construct(ComplaintRepository $ComplaintRepository)
    {
        $this->ComplaintRepository = $ComplaintRepository;
    }

    public function createTeacherComplaint(User $user, string $subject)
    {
     return $this->ComplaintRepository->createComplaint([
        'TeacherId' => $user->id,
        'Subject' => $subject,
        'Status' => 'Pending'
     ]);
    }


    public function editComplaint($request, $complaintId, $user)
    {
        $complaint = $this->ComplaintRepository->findComplaintWithUser($complaintId);

        if (!$complaint) {
            return [
                'status' => 'error',
                'message' => 'Complaint not found',
                'status_code' => 404,
            ];
        }

        if ($complaint->TeacherId !== $user->id) {
            return [
                'status' => 'error',
                'message' => 'You are not authorized to edit this complaint',
                'status_code' => 403,
            ];
        }

        if ($complaint->Status !== 'Pending') {
            return [
                'status' => 'error',
                'message' => 'Only pending complaints can be edited',
                'status_code' => 400,
            ];
        }

        $validator = Validator::make($request->all(), [
            'Subject' => 'required|string',
        ]);

        if ($validator->fails()) {
            return [
                'status' => 'error',
                'errors' => $validator->errors(),
                'status_code' => 422,
            ];
        }

        $updatedComplaint = $this->ComplaintRepository->updateSubject($complaint, $request->Subject);

        return [
            'status' => 'success',
            'message' => 'Complaint updated successfully',
            'data' => $updatedComplaint,
            'status_code' => 200,
        ];
    }

    public function deleteComplaint($complaintId, $teacherId)
    {
        $complaint = $this->ComplaintRepository->findByIdAndTeacher($complaintId, $teacherId);

        if (!$complaint) {
            return [
                'status' => 'error',
                'message' => 'Complaint not found or you are not authorized to delete it',
                'status_code' => 404,
            ];
        }

        $this->ComplaintRepository->delete($complaint);

        return [
            'status' => 'success',
            'message' => 'Complaint deleted successfully',
            'status_code' => 200,
        ];
    }

    public function getComplaintDetails($id)
    {
        $complaint = $this->ComplaintRepository->findComplaintWithUser($id);

        if (!$complaint) {
            return [
                'status' => 'error',
                'message' => 'Complaint not found',
                'status_code' => 404,
            ];
        }

        $user = $complaint->User;

        return [
            'status' => 'success',
            'data' => [
                'id' => $complaint->id,
                'subject' => $complaint->Subject,
                'status' => $complaint->Status,
                'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
                'teacher' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ] : null
            ],
            'status_code' => 200,
        ];
    }

    public function getAllComplaints()
    {
        $complaints = $this->ComplaintRepository->getAllWithUser();

        $data = $complaints->map(function ($complaint) {
            $user = $complaint->User;

            return [
                'id' => $complaint->id,
                'subject' => $complaint->Subject,
                'status' => $complaint->Status,
                'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
                'teacher' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ] : null
            ];
        });

        return [
            'status' => 'success',
            'data' => $data,
            'status_code' => 200,
        ];
    }

    public function getTeacherComplaints($teacherId)
    {
        $teacher = $this->ComplaintRepository->findTeacherById($teacherId);

        if (!$teacher) {
            return [
                'status' => 'error',
                'message' => 'Teacher not found or the role of auth is not a teacher',
                'status_code' => 404
            ];
        }

        $complaints = $this->ComplaintRepository->getByTeacherId($teacherId);

        return [
            'status' => 'success',
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email
                ],
                'complaints' => $complaints->map(function ($complaint) {
                    return [
                        'id' => $complaint->id,
                        'subject' => $complaint->Subject,
                        'status' => $complaint->Status,
                        'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s')
                    ];
                }),
                'count' => $complaints->count(),
                'pending_count' => $complaints->where('Status', 'Pending')->count(),
                'solved_count' => $complaints->where('Status', 'Solved')->count(),
            ],
            'status_code' => 200
        ];
    }

    public function getComplaintsByStatus(string $status)
    {
     $complaints = $this->ComplaintRepository->getComplaintsByStatus($status);

     if ($complaints->isEmpty()) {
        return [
            'status' => 'empty',
            'message' => "No {$status} complaints exist.",
           // 'status_code' => 404
        ];
     }

     $data = $complaints->map(function ($complaint) {
        return [
            'id' => $complaint->id,
            'subject' => $complaint->Subject,
            'status' => $complaint->Status,
            'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
            'teacher' => $complaint->User ? [
                'id' => $complaint->User->id,
                'name' => $complaint->User->name,
                'email' => $complaint->User->email
            ] : null
        ];
     });

     return [
        'status' => 'success',
        'data' => $data,
        'status_code' => 200
     ];
    }

    public function markComplaintAsSolved($complaintId)
    {
        $complaint = $this->ComplaintRepository->findComplaintWithUser($complaintId);

        if (!$complaint) {
            return [
                'status' => 'error',
                'message' => 'Complaint not found',
                'status_code' => 404
            ];
        }

        if ($complaint->Status === 'Solved') {
            return [
                'status' => 'info',
                'message' => 'This complaint was already marked as solved',
                'complaint_id' => $complaint->id,
                'previous_status' => $complaint->Status,
                'status_code' => 200
            ];
        }

        $complaint->update([
            'Status' => 'Solved',
            'updated_at' => now()
        ]);

        return [
            'status' => 'success',
            'message' => 'Complaint successfully marked as solved',
            'complaint_details' => [
                'id' => $complaint->id,
                'subject' => $complaint->Subject,
                'new_status' => $complaint->Status,
                'resolved_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
                'owner_notification' => [
                    'teacher_id' => $complaint->User->id,
                    'teacher_name' => $complaint->User->name,
                    'teacher_email' => $complaint->User->email,
                    'message' => "Your complaint '{$complaint->Subject}' has been resolved"
                ]
            ],
            'status_code' => 200
        ];
    }

    public function getTeacherownComplaints($user)
    {
        if (!$user->hasRole('Teacher')) {
            return [
                'status' => 'error',
                'message' => 'Only teachers can access their complaints.',
                'status_code' => 403,
            ];
        }

        $complaints = $this->ComplaintRepository->getByTeacherId($user->id);

        if ($complaints->isEmpty()) {
            return [
                'status' => 'success',
                'count' => 0,
                'message' => 'You currently have no complaints.',
                'data' => [],
                'status_code' => 200,
            ];
        }

        $data = $complaints->map(function ($complaint) {
            return [
                'id' => $complaint->id,
                'subject' => $complaint->Subject,
                'status' => $complaint->Status,
                'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
            ];
        });

        return [
            'status' => 'success',
            'count' => $data->count(),
            'data' => $data,
            'status_code' => 200,
        ];
    }

    public function getTeacherComplaintsByStatus($user, string $status)
    {
        if (!$user->hasRole('Teacher')) {
            return [
                'status' => 'error',
                'message' => 'Only teachers can access their complaints.',
                'status_code' => 403,
            ];
        }

        $complaints = $this->ComplaintRepository->getComplaintsByStatusForTeacher($user->id, $status);

        if ($complaints->isEmpty()) {
            return [
                'status' => 'success',
                'count' => 0,
                'message' => "No {$status} complaints exist for your account.",
                'data' => [],
                'status_code' => 200,
            ];
        }

        $data = $complaints->map(function ($complaint) {
            return [
                'id' => $complaint->id,
                'subject' => $complaint->Subject,
                'status' => $complaint->Status,
                'created_at' => $complaint->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $complaint->updated_at->format('Y-m-d H:i:s'),
            ];
        });

        return [
            'status' => 'success',
            'count' => $data->count(),
            'data' => $data,
            'status_code' => 200,
        ];
    }

}
