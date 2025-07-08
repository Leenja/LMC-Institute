<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    public function createInvoice(Request $request)
    {
        $request->validate([
            'TaskId' => 'required|integer|exists:tasks,id',
            'Amount' => 'required|numeric|min:0',
            'Image' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $creatorId = auth()->id();
        if (!$creatorId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $result = $this->invoiceService->createInvoice($request, $creatorId);

            return response()->json([
                'message' => 'Invoice created and sent successfully',
                'invoice' => $result['invoice'],
                'recipients' => $result['recipients'],
                'image_url' => $result['image_url']
            ], 201);
        } catch (\Exception $e) {
            $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
                ? (int)$e->getCode()
                : 500;

            return response()->json([
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    public function showInvoice($invoiceId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $result = $this->invoiceService->showInvoice($invoiceId, $userId);
            return response()->json($result);
        } catch (\Exception $e) {
            $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
                ? (int)$e->getCode()
                : 500;

            return response()->json([
                'message' => 'Error retrieving invoice: ' . $e->getMessage()
            ], $statusCode);
        }
    }

    public function checkInvoiceApproval($invoiceId)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $result = $this->invoiceService->checkApproval($invoiceId, $userId);
            return response()->json($result);
        } catch (\Exception $e) {
            $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
                ? (int)$e->getCode()
                : 500;

            return response()->json([
                'message' => 'Error checking approval status: ' . $e->getMessage()
            ], $statusCode);
        }
    }
    
    public function showAllInvoices(): JsonResponse
    {
        $result = $this->invoiceService->getFormattedInvoices();

        if (isset($result['message'])) {
            return response()->json($result, 200);
        }

        return response()->json($result, 200);
    }
}
