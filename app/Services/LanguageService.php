<?php

namespace App\Services;

use App\Repositories\LanguageRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Illuminate\Database\QueryException;
use Carbon\Carbon;


class LanguageService
{
    protected $languageRepository;

    public function __construct(LanguageRepository $languageRepository)
    {
        $this->languageRepository = $languageRepository;
    }

    

    public function addLanguage(array $data)
    {
        $validator = Validator::make($data, [
            'Name' => 'required|string|unique:languages,Name',
            'Description' => 'required|string',
        ]);

        if ($validator->fails()) {
            return [
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ];
        }

        return $this->languageRepository->createLanguage($data);
    }
    
    public function updateLanguage(int $id, array $data)
    {
        // تحقق من وجود اللغة أولًا
        $language = $this->languageRepository->findLanguageById($id);
        if (!$language) {
            return [
                'status' => false,
                'message' => "language with ID $id not found",
            ];
        }

        if (empty($data['Name']) && empty($data['Description'])) {
            return [
                'status' => false,
                'message' => 'No data provided to update.',
            ];
        }

        $validator = Validator::make($data, [
            'Name' => 'sometimes|string|unique:languages,Name,' . $id,
            'Description' => 'sometimes|string',
        ]);
    
        if ($validator->fails()) {
            return [
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ];
        }
    
        // تنفيذ التحديث
        $updatedLanguage = $this->languageRepository->updateLanguage($id, $data);
    
        return [
            'status' => true,
            'message' => 'Language updated successfully',
            'data' => $updatedLanguage,
        ];
    }


    public function deleteLanguage($LanguageId)
    {
        $Language = $this->languageRepository->findLanguageById($LanguageId);

        if (!$Language) {
            return [
                'status' => 'error',
                'message' => 'Language not found',
                'status_code' => 404,
            ];
        }

        $this->languageRepository->deleteLanguage($Language);

        return [
            'status' => 'success',
            'message' => 'Language deleted successfully',
            'status_code' => 200,
        ];
    }

    public function getLanguageDetails($id)
    {
        $Language = $this->languageRepository->findLanguageById($id);

        if (!$Language) {
            return [
                'status' => 'error',
                'message' => 'Language not found',
                'status_code' => 404,
            ];
        }

        return [
            'status' => 'success',
            'data' => [
                'id' => $Language->id,
                'Name' => $Language->Name,
                'Description' => $Language->Description,
                'created_at' => $Language->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $Language->updated_at->format('Y-m-d H:i:s'),
            ],
            'status_code' => 200,
        ];
    }

    public function getAllLanguage()
    {
        $Languages = $this->languageRepository->getAllLanguage();

        $data = $Languages->map(function ($Language) {

            return [
                'id' => $Language->id,
                'Name' => $Language->Name,
                'Description' => $Language->Description,
                'created_at' => $Language->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $Language->updated_at->format('Y-m-d H:i:s'),
            ];
        });

        return [
            'status' => 'success',
            'data' => $data,
            'status_code' => 200,
        ];
    }

}