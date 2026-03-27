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

        // Case 2: Model has project relationship (e.g. InventoryLog)
        if (method_exists($this, 'project')) {
            $project = $this->project;
            if ($project && $project instanceof \App\Models\Project) {
                return $project->enquiry;
            }
        }

        // Case 3: Model has project_id (linked to a Project model)
        if (isset($this->project_id) && $this->project_id) {
            $project = \App\Models\Project::find($this->project_id);
            return $project ? $project->enquiry : null;
        }

        // Case 3: Requisition relationship (for PurchaseOrders)
        if (method_exists($this, 'requisition') && $this->requisition) {
            return $this->requisition->projectEnquiry;
        }

        return null;
    }
}
