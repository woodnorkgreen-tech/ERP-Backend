<?php

namespace App\Modules\Assets\Services;

class AssetExportService
{
    /**
     * Download a blank CSV template with the exact column headers from the
     * WNG Asset Register spreadsheet — fill it in and re-upload via Bulk Import.
     */
    public function downloadTemplate()
    {
        $headers = [
            'Asset Tag No.',
            'Sub-Category',
            'Asset Name',
            'Category',
            'Department',
            'Location',
            'Manufacturer',
            'Model',
            'Serial Number',
            'Process type and speed',
            'Purchase Date',
            'Supplier',
            'Qty',
            'Purchase Cost (USD)',
            'Purchase Cost (KES)',
            'Current Value (KES)',
            'Condition',
            'Assigned To',
            'Notes',
        ];

        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=asset_register_template.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }
}
