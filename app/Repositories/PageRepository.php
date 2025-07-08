<?php

namespace App\Repositories;

use App\Models\Page;

class PageRepository
{
    public function create(array $data)
    {
        return Page::create($data);
    }

    public function find($id)
    {
        return Page::findOrFail($id);
    }

    public function getAll($isContact = null)
    {
        $query = Page::query();

        if (!is_null($isContact)) {
            $query->where('is_contact', (bool)$isContact);
        }

        return $query->get();
    }

    public function update(Page $page, array $data)
    {
        $page->update($data);
        return $page;
    }

    public function delete(Page $page)
    {
        return $page->delete();
    }
}
