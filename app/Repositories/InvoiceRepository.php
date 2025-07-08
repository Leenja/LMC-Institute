<?php

namespace App\Repositories;

use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceRepository
{
    public function findTask($taskId)
    {
        return Task::find($taskId);
    }

    public function createInvoice(array $data)
    {
        return Invoice::create($data);
    }

    public function findInvoiceWithRelations($invoiceId)
    {
        return Invoice::with(['task', 'creator', 'recipients.user'])->findOrFail($invoiceId);
    }

    public function findRecipient($invoiceId, $userId)
    {
        return InvoiceRecipient::where('InvoiceId', $invoiceId)
            ->where('UserId', $userId)->first();
    }

    public function countPendingRecipients($invoiceId)
    {
        return InvoiceRecipient::where('InvoiceId', $invoiceId)
            ->where('Status', '!=', 'Approved')->count();
    }

    public function updateInvoiceStatus(Invoice $invoice, $status)
    {
        $invoice->update(['Status' => $status]);
    }

    public function getAllInvoicesWithRelations()
    {
        return Invoice::with(['task', 'creator', 'recipients.user'])->get();
    }

    /*public function getSecretaries()
    {
        return User::where('role', 'Secretarya')->get();
    }*/

    public function getSecretaries()
    {
        // First find the role_id where role name is 'Secretarya'
        $secretaryRoleId = DB::table('roles')
            ->where('name', 'Secretarya')
            ->value('id');

        if (!$secretaryRoleId) {
            return collect(); // Return empty collection if role not found
        }

        return User::where('role_id', $secretaryRoleId)->get();
    }

    public function createRecipient($invoiceId, $userId)
    {
        return InvoiceRecipient::create([
            'InvoiceId' => $invoiceId,
            'UserId' => $userId,
            'Status' => 'Pending',
        ]);
    }

    public function findInvoiceByIdWithRecipients($invoiceId)
    {
        return Invoice::with(['recipients.user'])->findOrFail($invoiceId);
    }
}
