<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\Workstation;

class MaterialSchemaService
{
    public function getSchema(Workstation $workstation): array
    {
        $commonFields = [
            // Standard fields are handled separately in the form (Code, Name, Category, UOM, Cost)
            // This schema is for EXTRA JSON attributes
            // However, for some workstations, 'Cost per Roll' etc are attributes.
        ];

        switch ($workstation->code) {
            case 'LFP':
                return [
                    ['key' => 'cost_per_roll', 'label' => 'Cost per Roll', 'type' => 'number'],
                    ['key' => 'cost_per_sqm', 'label' => 'Cost per Sqm', 'type' => 'number'],
                    ['key' => 'technical_spec', 'label' => 'Technical Spec', 'type' => 'text'],
                    ['key' => 'finish', 'label' => 'Finish', 'type' => 'text'],
                    ['key' => 'gsm_micron', 'label' => 'GSM/Micron', 'type' => 'text'],
                    ['key' => 'roll_dimensions', 'label' => 'Roll Dimensions', 'type' => 'text'],
                    ['key' => 'durability_outdoor', 'label' => 'Durability (Outdoor)', 'type' => 'text'],
                    ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                    ['key' => 'printer_compatibility', 'label' => 'Printer Compatibility', 'type' => 'text'],
                    ['key' => 'ink_compatibility', 'label' => 'Ink Compatibility', 'type' => 'text'],
                    ['key' => 'adhesive_type', 'label' => 'Adhesive Type', 'type' => 'text'],
                    ['key' => 'liner_type', 'label' => 'Liner Type', 'type' => 'text'],
                    ['key' => 'application_surface', 'label' => 'Application Surface', 'type' => 'text'],
                    ['key' => 'typical_applications', 'label' => 'Typical Applications', 'type' => 'textarea'],
                    ['key' => 'brand_manufacturer', 'label' => 'Brand / Manufacturer', 'type' => 'text'],
                    ['key' => 'supplier_vendor', 'label' => 'Supplier / Vendor', 'type' => 'text'],
                    ['key' => 'supplier_code', 'label' => 'Supplier Code', 'type' => 'text'],
                ];
            
            case 'CNC':
                return [
                    ['key' => 'description_spec', 'label' => 'Description / Spec', 'type' => 'textarea'],
                    ['key' => 'sheet_size_dimensions', 'label' => 'Sheet Size / Dimensions', 'type' => 'text'],
                    ['key' => 'thickness', 'label' => 'Thickness', 'type' => 'text'],
                    ['key' => 'density_grade', 'label' => 'Density / Grade', 'type' => 'text'],
                    ['key' => 'finish_face', 'label' => 'Finish / Face', 'type' => 'text'],
                    ['key' => 'grain_direction', 'label' => 'Grain Direction', 'type' => 'text'],
                    ['key' => 'core_type', 'label' => 'Core Type', 'type' => 'text'],
                    ['key' => 'fire_rating', 'label' => 'Fire Rating', 'type' => 'text'],
                    ['key' => 'machine_compatibility', 'label' => 'Machine Compatibility', 'type' => 'text'],
                    ['key' => 'preferred_supplier', 'label' => 'Preferred Supplier', 'type' => 'text'],
                ];

            case 'LASER':
            case 'UV':
            case 'MET':
            case 'CARP':
            case 'PAINT':
            case 'LED':
            case 'GEN':
                // Simplified default + specific fields
                $fields = [
                    ['key' => 'description_spec', 'label' => 'Description / Spec', 'type' => 'textarea'],
                    ['key' => 'preferred_supplier', 'label' => 'Preferred Supplier', 'type' => 'text'],
                ];

                if (in_array($workstation->code, ['LASER', 'UV'])) {
                    $fields[] = ['key' => 'sheet_size', 'label' => 'Sheet Size', 'type' => 'text'];
                    $fields[] = ['key' => 'thickness', 'label' => 'Thickness', 'type' => 'text'];
                }
                
                if ($workstation->code === 'LED') {
                     $fields[] = ['key' => 'dimensions', 'label' => 'Dimensions', 'type' => 'text'];
                     $fields[] = ['key' => 'voltage', 'label' => 'Voltage (V)', 'type' => 'text'];
                     $fields[] = ['key' => 'power', 'label' => 'Power (W)', 'type' => 'text'];
                     $fields[] = ['key' => 'color_temp', 'label' => 'Color Temp (K)', 'type' => 'text'];
                     $fields[] = ['key' => 'ip_rating', 'label' => 'IP Rating', 'type' => 'text'];
                }

                if ($workstation->code === 'PAINT') {
                    $fields[] = ['key' => 'color_code', 'label' => 'Color Code', 'type' => 'text'];
                    $fields[] = ['key' => 'finish_type', 'label' => 'Finish Type', 'type' => 'text'];
                }

                return $fields;

            default:
                return [];
        }
    }
}
