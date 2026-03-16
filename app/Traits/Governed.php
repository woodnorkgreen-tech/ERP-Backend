<?php

namespace App\Traits;

use App\Models\ProjectEnquiry;
use App\Services\Governance\ProjectGovernanceService;
use App\Exceptions\GovernanceException;
use Illuminate\Support\Facades\Log;

trait Governed
{
    /**
     * Boot the trait and register Eloquent observers.
     */
    public static function bootGoverned()
    {
        static::creating(function ($model) {
            $gate = method_exists($model, 'getGovernanceGate') ? $model->getGovernanceGate() : 'expenditure';
            $model->validateGovernance($gate, ['amount' => $model->total_amount ?? $model->quantity ?? 0]);
        });

        static::updating(function ($model) {
            // Only check if amount or project changed
            if ($model->isDirty(['total_amount', 'quantity', 'project_id', 'enquiry_id'])) {
                $gate = method_exists($model, 'getGovernanceGate') ? $model->getGovernanceGate() : 'expenditure';
                $model->validateGovernance($gate, ['amount' => $model->total_amount ?? $model->quantity ?? 0]);
            }
        });
    }

    /**
     * Validate the model against the Governance Service.
     */
    public function validateGovernance(string $gateType, array $context = [])
    {
        $enquiry = $this->resolveProjectEnquiry();

        if (!$enquiry) {
            return; // No project linked, skip governance (or handle as standalone)
        }

        $service = app(ProjectGovernanceService::class);
        $result = $service->checkGate($enquiry, $gateType, $context);

        if (!$result->isAuthorized()) {
            Log::warning("Governance Blokade: " . $result->getMessage());
            throw new GovernanceException($result->getMessage(), $result->context);
        }
    }

    /**
     * Resolve the underlying ProjectEnquiry for the model.
     */
    protected function resolveProjectEnquiry(): ?ProjectEnquiry
    {
        // Case 1: Model has enquiry_id
        if (isset($this->enquiry_id) && $this->enquiry_id) {
            return ProjectEnquiry::find($this->enquiry_id);
        }

        // Case 2: Model has project_id (linked to an enquiry)
        if (isset($this->project_id) && $this->project_id) {
            // Some models use project_id to point to the enquiry table
            return ProjectEnquiry::find($this->project_id);
        }

        // Case 3: Requisition relationship (for PurchaseOrders)
        if (method_exists($this, 'requisition') && $this->requisition) {
            return $this->requisition->projectEnquiry;
        }

        return null;
    }
}
