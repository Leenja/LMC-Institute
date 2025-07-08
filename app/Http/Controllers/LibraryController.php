<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Library;
use App\Services\LibraryService;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    protected $service;

    public function __construct(LibraryService $service)
    {
        $this->service = $service;
    }

    public function getLanguages()
    {
        return response()->json($this->service->getLanguages());
    }

    public function getFilesByLanguage($languageId)
    {
        try {
            $result = $this->service->getFilesByLanguage($languageId);
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function uploadFile(Request $request)
    {
        $validated = $request->validate([
            'LibraryId' => 'required|exists:libraries,id',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,bmp,svg,heic,tiff,tif,mp4,avi,mkv,mov,wmv,flv,webm,m4v,mp3,wav,ogg,aac,wma,flac,m4a'/*|max:51200'*/,
            'Description' => 'required|string'
        ]);

        try {
            $result = $this->service->uploadFile($validated, $request->file('file'));

            return response()->json([
                'message' => 'File is uploaded successfully',
                'item' => $result['item'],
                'file_url' => $result['file_url'],
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function addLanguageToLibrary(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:languages,id',
        ]);

        $language = Language::findOrFail($request->language_id);

        try {
            $library = $this->service->addLanguageToLibrary($request->language_id);

            return response()->json([
                'message' => "Library was created for this language: {$language->Name}",
                'Library' => $library
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create library',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteLibraryForLanguage($id)
    {
        $library = Library::with('language')->find($id);

        if (!$library) {
            return response()->json([
                'message' => "Library with ID {$id} not found or already deleted.",
            ], 404);
        }

        try {
            $result = $this->service->deleteLibraryWithFiles($library->id);
            $languageName = $library->language->Name ?? 'Unknown Language';

            switch ($result) {
                case 'deleted':
                    return response()->json([
                        'message' => "Library '{$languageName}' (ID: {$library->id}) and all related files/items have been deleted successfully.",
                    ]);
                case 'no_items':
                    return response()->json([
                        'message' => "Library '{$languageName}' (ID: {$library->id}) was deleted. No items or files were associated with it.",
                    ]);
                case 'not_found':
                default:
                    return response()->json([
                        'message' => "Library with ID {$id} not found or already deleted.",
                    ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete library due to an internal error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function editFile(Request $request, $id)
    {
        $validated = $request->validate([
            'Description' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,bmp,svg,heic,
            tiff,tif,mp4,avi,mkv,mov,wmv,flv,webm,m4v,mp3,wav,ogg,aac,wma,flac,m4a'
        ]);

        try {
            $result = $this->service->editFile($id, $validated, $request->file('file'));

            return response()->json([
                'message' => 'File was edited successfully',
                'item' => $result['item'],
                'file_url' => $result['file_url'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }


    public function deleteFile($id)
    {
        try {
            $this->service->deleteFile($id);
            return response()->json(['message' => 'File was deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function downloadFile($id)
    {
        return $this->service->downloadFile($id);
    }

    /*public function getLibraryByLanguage($languageId)
    {
        $library = Library::where('LanguageId', $languageId)->first();
        if (!$library) {
            return response()->json(['message' => 'Library not found'], 404);
        }
        return response()->json($library);
    }*/

    public function getLibraryByLanguage($languageId)
    {
        $library = Library::with('language') // تحميل علاقة اللغة
            ->where('LanguageId', $languageId)
            ->first();

        if (!$library) {
            return response()->json(['message' => 'Library not found'], 404);
        }

        return response()->json($library);
    }
}
