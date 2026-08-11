<?php

namespace App\Modules\Design\Services;

use App\Modules\Design\Models\DesignItem;
use Illuminate\Validation\ValidationException;

class DesignItemReadinessService
{
    public function ensurePrintReady(DesignItem $item): void
    {
        $errors = [];

        if ($item->stream !== DesignItem::STREAM_GRAPHIC) {
            $errors['stream'][] = 'Only Graphic Designs can be marked print ready.';
        }

        foreach (['length_m', 'width_m', 'quantity'] as $field) {
            if (empty($item->{$field})) {
                $errors[$field][] = "The {$field} field is required for print readiness.";
            }
        }

        if (empty($item->print_material_id) && !str_contains((string) $item->print_notes, 'Print material:')) {
            $errors['print_material'][] = 'Select a print material or enter a temporary material name.';
        }

        if (!$item->documents()->where('status', 'active')->exists()) {
            $errors['documents'][] = 'At least one active final document/artwork is required.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function ensureProductionReady(DesignItem $item): void
    {
        $errors = [];

        if ($item->stream !== DesignItem::STREAM_STRUCTURAL) {
            $errors['stream'][] = 'Only Structural Designs can be marked production ready.';
        }

        foreach (['length_m', 'width_m', 'height_m', 'quantity'] as $field) {
            if (empty($item->{$field})) {
                $errors[$field][] = "The {$field} field is required for production readiness.";
            }
        }

        if (!$item->documents()->where('status', 'active')->exists()) {
            $errors['documents'][] = 'At least one active final render/document is required.';
        }

        if (!$item->bomItems()->exists()) {
            $errors['bom'][] = 'At least one BOM item is required for production readiness.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
