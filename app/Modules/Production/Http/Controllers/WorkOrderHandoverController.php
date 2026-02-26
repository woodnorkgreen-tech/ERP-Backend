<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderHandover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WorkOrderHandoverController extends Controller
{
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $handovers = WorkOrderHandover::where('work_order_id', $workOrder->id)
            ->with(['creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $handovers
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'job_number' => 'required|string|max:190',
            'client_name' => 'required|string|max:190',
            'project' => 'nullable|string|max:190',
            'description' => 'nullable|string',
            'quantity' => 'nullable|string|max:50',
            'condition' => 'nullable|string|max:120',
            'handed_over_by' => 'nullable|string|max:190',
            'received_by' => 'nullable|string|max:190',
            'remarks' => 'nullable|string'
        ]);

        $handover = WorkOrderHandover::create([
            'work_order_id' => $workOrder->id,
            'job_number' => $validated['job_number'],
            'client_name' => $validated['client_name'],
            'project' => $validated['project'] ?? null,
            'description' => $validated['description'] ?? null,
            'quantity' => $validated['quantity'] ?? null,
            'condition' => $validated['condition'] ?? null,
            'handed_over_by' => $validated['handed_over_by'] ?? null,
            'received_by' => $validated['received_by'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Handover created',
            'data' => $handover
        ], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, WorkOrderHandover $handover): JsonResponse
    {
        if ($handover->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Handover does not belong to this work order'
            ], 403);
        }

        $validated = $request->validate([
            'job_number' => 'sometimes|required|string|max:190',
            'client_name' => 'sometimes|required|string|max:190',
            'project' => 'sometimes|nullable|string|max:190',
            'description' => 'sometimes|nullable|string',
            'quantity' => 'sometimes|nullable|string|max:50',
            'condition' => 'sometimes|nullable|string|max:120',
            'handed_over_by' => 'sometimes|nullable|string|max:190',
            'received_by' => 'sometimes|nullable|string|max:190',
            'remarks' => 'sometimes|nullable|string'
        ]);

        $handover->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Handover updated',
            'data' => $handover
        ]);
    }

    public function destroy(WorkOrder $workOrder, WorkOrderHandover $handover): JsonResponse
    {
        if ($handover->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Handover does not belong to this work order'
            ], 403);
        }

        $handover->delete();

        return response()->json([
            'success' => true,
            'message' => 'Handover deleted'
        ]);
    }

    public function pdf(WorkOrder $workOrder, WorkOrderHandover $handover): Response
    {
        if ($handover->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Handover does not belong to this work order'
            ], 403);
        }

        try {
            // Generate PDF
            $pdf = $this->generateHandoverPdf($workOrder, $handover);
            
            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="handover-' . $handover->id . '.pdf"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (\Exception $e) {
            \Log::error('PDF Generation Error: ' . $e->getMessage());
            \Log::error('PDF Generation Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateHandoverPdf(WorkOrder $workOrder, WorkOrderHandover $handover): string
    {
        // Create new PDF instance using DomPDF
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper('A4', 'portrait');

        // Build HTML content
        $html = $this->buildHandoverHtml($workOrder, $handover);
        
        // Load HTML to PDF
        $pdf->loadHtml($html);
        $pdf->render();
        
        // Return PDF as string
        return $pdf->output();
    }

    private function buildHandoverHtml(WorkOrder $workOrder, WorkOrderHandover $handover): string
    {
        $companyName = $workOrder->projectEnquiry?->client?->company_name ?? $workOrder->projectEnquiry?->client?->full_name ?? 'N/A';
        $projectName = $handover->project ?? $workOrder->title ?? 'N/A';
        $handoverDate = date('F j, Y', strtotime($handover->created_at));
        $generatedDate = date('F j, Y H:i:s');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Handover Form</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 10px; }
        .subtitle { font-size: 14px; color: #666; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .info-table th { background-color: #f5f5f5; padding: 10px; text-align: left; font-weight: bold; border: 1px solid #ddd; }
        .info-table td { padding: 10px; border: 1px solid #ddd; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
        .signature-box { margin-top: 30px; border: 1px solid #ddd; padding: 20px; }
        .signature-line { border-bottom: 1px solid #333; height: 30px; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">HANDOVER FORM</div>
        <div class="subtitle">Production Handover Documentation</div>
    </div>
    
    <div class="section">
        <table class="info-table">
            <tr>
                <th>Job Number</th>
                <td>' . htmlspecialchars($handover->job_number) . '</td>
            </tr>
            <tr>
                <th>Client</th>
                <td>' . htmlspecialchars($companyName) . '</td>
            </tr>
            <tr>
                <th>Project</th>
                <td>' . htmlspecialchars($projectName) . '</td>
            </tr>
            <tr>
                <th>Date</th>
                <td>' . htmlspecialchars($handoverDate) . '</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <div class="section-title">Handover Details</div>
        <table class="info-table">
            <tr>
                <th>Items Quantity</th>
                <td>' . htmlspecialchars($handover->quantity) . ' items</td>
            </tr>
            <tr>
                <th>Condition</th>
                <td>' . htmlspecialchars(ucfirst($handover->condition)) . '</td>
            </tr>
            <tr>
                <th>Description</th>
                <td>' . htmlspecialchars($handover->description) . '</td>
            </tr>
            <tr>
                <th>Remarks</th>
                <td>' . htmlspecialchars($handover->remarks) . '</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <div class="section-title">Personnel</div>
        <table class="info-table">
            <tr>
                <th>Handed Over By</th>
                <td>' . htmlspecialchars($handover->handed_over_by) . '</td>
            </tr>
            <tr>
                <th>Received By</th>
                <td>' . htmlspecialchars($handover->received_by) . '</td>
            </tr>
        </table>
    </div>
    
    <div class="signature-box">
        <table class="info-table">
            <tr>
                <th width="50%">Handed Over By Signature</th>
                <th width="50%">Received By Signature</th>
            </tr>
            <tr>
                <td>
                    <div class="signature-line"></div>
                    <small>Date: _________________</small>
                </td>
                <td>
                    <div class="signature-line"></div>
                    <small>Date: _________________</small>
                </td>
            </tr>
        </table>
    </div>
    
    <div class="footer">
        <p>This document serves as official proof of handover between the Production Department and the receiving party.</p>
        <p>Generated on: ' . htmlspecialchars($generatedDate) . '</p>
    </div>
</body>
</html>';
        
        return $html;
    }
}
