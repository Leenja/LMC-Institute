<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Library;
use App\Repositories\LibraryRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Exception;

class LibraryService
{
    protected $repository;

    public function __construct(LibraryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getLanguages()
    {
        return $this->repository->getLanguagesWithLibrary();
    }

    public function getFilesByLanguage($languageId)
    {
        $language = $this->repository->findLanguageWithLibraryAndItems($languageId);

        if (!$language) {
            throw new \Exception('This language is not found', 404);
        }

        // Check if language has a library
        if (!$language->library) {
            return [
                'message' => 'This language does not have a library',
                'language' => $language->Name,
            ];
        }

        return [
            'language' => $language->Name,
            'files' => $language->library->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'file_name' => basename($item->File),
                    'url' => url('storage/' . $item->File),
                    'description' => $item->Description,
                ];
            })
        ];
    }



    public function uploadFile($data, $file)
    {
        DB::beginTransaction();

        try {
            if (!$file) {
                throw new Exception('File is required', 400);
            }

            $newName = time() . '_' . $file->getClientOriginalName();
            $destinationPath = public_path('storage/library_files/' . $data['LibraryId']);

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $newName);

            $relativePath = 'storage/library_files/' . $data['LibraryId'] . '/' . $newName;
            $fullPath = public_path($relativePath);

            if (!file_exists($fullPath)) {
                throw new Exception('Failed to upload file', 500);
            }

            $item = $this->repository->createItem([
                'LibraryId' => $data['LibraryId'],
                'File' => 'library_files/' . $data['LibraryId'] . '/' . $newName, // store relative path
                'Description' => $data['Description'],
            ]);

            DB::commit();

            return [
                'item' => $item,
                'file_url' => url($relativePath)
            ];
        } catch (Exception $e) {
            DB::rollBack();

            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }

            throw $e;
        }
    }


    public function addLanguageToLibrary($languageId)
    {
        $existing = $this->repository->getLibraryByLanguage($languageId);

        if ($existing) {
            throw new \Exception('This language already has a library', 409);
        }

        return $this->repository->createLibrary($languageId);
    }

    public function deleteLibraryWithFiles($libraryId)
    {
        $library = Library::with('language')->where('id', $libraryId)->first();

        if (!$library) {
            return 'not_found';
        }

        $items = Item::where('LibraryId', $library->id)->get();

        $folderPath = public_path("storage/library_files/{$library->id}");

        if ($items->isEmpty()) {
            if (file_exists($folderPath)) {
                $this->deleteDirectoryRecursively($folderPath);
            }

            $library->delete();
            return 'no_items';
        }

        foreach ($items as $item) {
            if ($item->File) {
                $fileFullPath = public_path('storage/' . $item->File);
                if (file_exists($fileFullPath)) {
                    unlink($fileFullPath);
                }
            }
            $item->delete();
        }

        if (file_exists($folderPath)) {
            $this->deleteDirectoryRecursively($folderPath);
        }

        $library->delete();

        return 'deleted';
    }

    private function deleteDirectoryRecursively($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursively($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }



    public function editFile($id, $data, $file = null)
    {
        DB::beginTransaction();

        try {
            $item = $this->repository->findItemById($id);

            if (!$item) {
                throw new Exception('File not found', 404);
            }

            $hasNewDescription = isset($data['Description']) && $data['Description'] !== $item->Description;
            $hasNewFile = $file !== null;

            if (!$hasNewDescription && !$hasNewFile) {
                throw new Exception('No data provided to update.', 422);
            }

            $newRelativePath = null;
            $fullPath = null;

            if ($hasNewFile) {
                $oldFilePath = public_path('storage/' . $item->File);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }

                $newName = time() . '_' . $file->getClientOriginalName();
                $destinationPath = public_path('storage/library_files/' . $item->LibraryId);

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $file->move($destinationPath, $newName);

                $newRelativePath = 'library_files/' . $item->LibraryId . '/' . $newName;
                $fullPath = public_path('storage/' . $newRelativePath);

                if (!file_exists($fullPath)) {
                    throw new Exception('Failed to upload new file', 500);
                }

                $item->File = $newRelativePath;
            }

            if ($hasNewDescription) {
                $item->Description = $data['Description'];
            }

            $item->save();

            DB::commit();

            return [
                'item' => $item,
                'file_url' => url('storage/' . $item->File)
            ];
        } catch (Exception $e) {
            DB::rollBack();

            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }

            throw $e;
        }
    }


    public function deleteFile($id)
    {
        $item = $this->repository->findItemById($id);

        if (!$item) {
            throw new \Exception('File not found', 404);
        }

        $fullPath = public_path('storage/' . $item->File);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        $this->repository->deleteItem($item);
    }


    public function downloadFile($id)
    {
        $item = $this->repository->findItemById($id);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $relativePath = 'storage/' . $item->File;
        $fullPath = public_path($relativePath);

        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'File not found on server'], 404);
        }

        return response()->download($fullPath, basename($fullPath), [
            'Content-Type' => mime_content_type($fullPath),
            'Content-Disposition' => 'attachment; filename="' . basename($fullPath) . '"',
        ]);
    }
}
