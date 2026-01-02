<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\Workstation;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

class MaterialExportService
{
    /**
     * Download a template for a specific workstation.
     */
    public function downloadTemplate($workstationId)
    {
        $workstation = Workstation::findOrFail($workstationId);
        $headers = $this->getHeadersForWorkstation($workstation);

        // Simple CSV export for now, or use PHPOffice for real XLSX
        // Using a simple csv callback for simplicity and speed without extra classes
        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            fclose($file);
        };

        $filename = Str::slug($workstation->name) . '_template.csv';

        return response()->stream($callback, 200, [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ]);
    }

    /**
     * Get headers based on workstation code (Hardcoded definition source of truth).
     */
    private function getHeadersForWorkstation(Workstation $workstation)
    {
        // Common base
        $common = ['SKU Code', 'Material / Item Name', 'Category', 'Sub-Category', 'UOM'];

        switch ($workstation->code) {
            case 'LFP':
                return [
                    'Line #', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Vinyl Type', 'Technical Spec', 'Finish', 'GSM/Micron', 'Material/Item Name', 
                    'Color', 'Adhesive Type', 'Durability (Years)', 'UOM', 'Primary Application', 'Typical Job Types', 'Roll Dimensions', 'Cost per Roll', 'Cost per Sqm', 'Default Store Location', 
                    'Critical? (Y/N)', 'Min Stock Level', 'Max Stock Level', 'Reorder Point'
                ];
            
            case 'CNC':
                return [
                    'Line#', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material Name', 
                    'Size', 'Technical Spec', 'Thickness / Size', 'Density / Grade', 'UOM', 'Color', 
                    'Application', 'Machine Compatibility', 'Preferred Supplier', 'Store Location', 
                    'Critical? (Y/N)', 'Min Stock', 'Max Stock', 'Reorder Point'
                ];

            case 'LASER':
                return [
                    'Line#', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'Sheet Size', 'Thickness', 'Material Type', 'Finish', 
                    'Masking Type', 'Cut Speed Setting (Ref)', 'Power Setting (Ref)', 'Preferred Supplier', 
                    'Issue Unit', 'Notes'
                ];

            case 'UV':
                return [
                    'Line#', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Sheet Size', 'Thickness', 'Print Surface Finish', 
                    'White Ink Compatible?', 'Primer Required?', 'Adhesion Level', 'Outdoor Durability', 
                    'Preferred Supplier', 'Issue Unit'
                ];

            case 'MET':
                return [
                    'Line#', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Profile / Shape', 'Dimensions / Size', 'Wall Thickness / Gauge', 
                    'Material Grade', 'Finish', 'Weldability', 'Length per Piece', 'Preferred Supplier', 'Issue Unit'
                ];

            case 'CARP':
                return [
                    'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Preferred Supplier', 'Default Store Location', 'Issue Unit', 
                    'Min Stock Level', 'Max Stock Level', 'Reorder Point', 'Critical? (Y/N)', 'Typical Job Types', 'Notes'
                ];

            case 'PAINT':
                return [
                    'Line #', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Container Size', 'Color Code (RAL/Pantone)', 'Finish Type', 
                    'Base Type', 'Thinning Ratio', 'Drying Time', 'Coverage (sqm/l)', 'Preferred Supplier', 'Notes'
                ];

            case 'LED':
                return [
                    'Line #', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Dimensions', 'Voltage (V)', 'Power (W)', 'Color Temp (K) / Color', 
                    'IP Rating', 'Lumens', 'Beam Angle', 'Preferred Supplier', 'Default Store Location', 'Issue Unit', 'Notes'
                ];

            case 'GEN':
                return [
                    'Line #', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Dimension', 'Preferred Supplier', 'Default Store Location', 
                    'Issue Unit', 'Min Stock Level', 'Max Stock Level', 'Reorder Point', 
                    'Critical? (Y/N)', 'Typical Job Types', 'Notes'
                ];

            default:
                // Default fallback pattern (Pattern C simplified)
                return [
                    'Line#', 'Workstation', 'SKU Code', 'Category', 'Sub-Category', 'Material / Item Name', 
                    'Description / Spec', 'UOM', 'Preferred Supplier', 'Notes'
                ];
        }
    }
}
