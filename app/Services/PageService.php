<?php

namespace App\Services;

use App\Repositories\PageRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class PageService
{
    protected $pageRepo;

    public function __construct(PageRepository $pageRepo)
    {
        $this->pageRepo = $pageRepo;
    }

    public function addPage(Request $request, array $data)
    {
        DB::beginTransaction();
        try {
            /*$image = $request->file('photo');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $path = public_path('storage/page');

            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            $image->move($path, $imageName);
            $fullPath = 'storage/page/' . $imageName;

            if (!file_exists(public_path($fullPath))) {
                throw new Exception('Failed to upload image', 500);
            }

            $data['photo'] = url($fullPath);*/
            $page = $this->pageRepo->create($data);

            DB::commit();

            return response()->json(['page' => $page, /*'image_url' => $data['photo']*/], 201);
        } catch (Exception $e) {
            DB::rollBack();
            /*if (isset($fullPath) && file_exists(public_path($fullPath))) {
                unlink(public_path($fullPath));
            }*/

            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function showPage($id)
    {
        return response()->json($this->pageRepo->find($id));
    }

    public function showAllPages(Request $request)
    {
        $isContact = $request->input('is_contact');
        return response()->json($this->pageRepo->getAll($isContact));
    }

    public function updatePage(Request $request, $id, array $data)
    {
        DB::beginTransaction();
        try {
            $page = $this->pageRepo->find($id);

           /* if ($request->hasFile('photo')) {
                $image = $request->file('photo');
                $imageName = time() . '_' . $image->getClientOriginalName();

                $destination = public_path('storage/page');
                if (!file_exists($destination)) {
                    mkdir($destination, 0755, true);
                }

                $image->move($destination, $imageName);
                $fullPath = 'storage/page/' . $imageName;

                if (!file_exists(public_path($fullPath))) {
                    throw new Exception('Failed to upload image', 500);
                }

                // حذف الصورة القديمة
                if ($page->photo) {
                    $oldImagePath = str_replace(url('/') . '/', '', $page->photo);
                    if (file_exists(public_path($oldImagePath))) {
                        unlink(public_path($oldImagePath));
                    }
                }

                $data['photo'] = url($fullPath);
            }*/

            $page = $this->pageRepo->update($page, $data);
            DB::commit();

            return response()->json($page);
        } catch (Exception $e) {
            DB::rollBack();
            /*if (isset($fullPath) && file_exists(public_path($fullPath))) {
                unlink(public_path($fullPath));
            }*/

            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function deletePage($id)
    {
        $page = $this->pageRepo->find($id);

        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $this->pageRepo->delete($page);

        return response()->json(['message' => 'Page deleted successfully']);
    }
}
