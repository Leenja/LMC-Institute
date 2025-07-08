<?php

namespace App\Repositories;

use App\Models\IndividualCourseRequest;

class IndividualCourseRequestRepository
{

    public function createRequest($userId, $languageId , $description)
    {
        return IndividualCourseRequest::create([
            'user_id'     => $userId,
            'language_id' => $languageId,
            'description' => $description,
            'status'      => 'Pending',
        ]);
    }

    public function find($id)
    {
        return IndividualCourseRequest::find($id);
    }
}
