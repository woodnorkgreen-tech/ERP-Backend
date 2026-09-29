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
        if (($item->destination ?: 'printing') !== 'printing') {
            $errors['destination'][] = 'Choose Printing as the destination before sending this item to Printing.';
        }

        foreach (['length_m', 'width_m', 'quantity'] as $field) {
            if (empty($item->{$field})) {
                $errors[$field][] = "The {$field} field is required for print readiness.";
            }
        }

        if (empty($item->print_material_id) && !str_contains((string) $item->print_notes, 'Print material:')) {
            $errors['print_material'][] = 'Select a print material or enter a temporary material name.';
        }

        if (!$item->documents()
            ->where('status', 'active')
            ->where('document_type', 'artwork')
            ->where('source', 'link')
            ->exists()) {
            $errors['documents'][] = 'Attach an active Artwork link before marking this item print ready.';
        }

        $this->requireLatestApprovedRevision($item, $errors);

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
        if (($item->destination ?: 'production') !== 'production') {
            $errors['destination'][] = 'Choose Production as the destination before sending this item to Production.';
        }

        foreach (['length_m', 'width_m', 'height_m', 'quantity'] as $field) {
            if (empty($item->{$field})) {
                $errors[$field][] = "The {$field} field is required for production readiness.";
            }
        }

        if (!$item->documents()
            ->where('status', 'active')
            ->whereIn('document_type', ['render', 'technical_drawing', 'model_file'])
            ->exists()) {
            $errors['documents'][] = 'At least one active final render, technical drawing, or model file is required.';
        }

        if (!$item->bomItems()->exists()) {
            $errors['bom'][] = 'At least one BOM item is required for production readiness.';
        }

        $this->requireLatestApprovedRevision($item, $errors);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function requireLatestApprovedRevision(DesignItem $item, array &$errors): void
    {
        $latest = $item->revisions()->orderByDesc('version_number')->first();
        if ($latest && $latest->status !== 'approved') {
            $errors['revision'][] = "Approve artwork version {$latest->version_number} and record the approval evidence first.";
        }

        if ($item->changeRequests()->where('status', 'open')->exists()) {
            $errors['change_requests'][] = 'Address all open client changes before marking this work ready.';
        }
    }
}
