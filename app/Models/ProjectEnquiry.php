<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Constants\EnquiryConstants;
use App\Constants\Permissions;

class ProjectEnquiry extends Model
{
    use HasFactory;

    protected $table = 'project_enquiries';

    protected $fillable = [
        'date_received',
        'expected_delivery_date',
        'client_id',
        'title',
        'description',
        'project_scope',
        'priority',
        'status',
        'department_id',
        'assigned_department',
        'estimated_budget',
        'contact_person',
        'project_officer_id',
        'assigned_po',
        'follow_up_notes',
        'enquiry_number',
        'job_number',
        'venue',
        'site_survey_skipped',
        'site_survey_skip_reason',
        'selected_workflow_tasks',
        'workflow_preset_type',
        'quote_approved',
        'quote_approved_at',
        'quote_approved_by',
        'created_by',
        'start_date',
        'end_date',
        'budget',
        'current_phase',
        'assigned_users',
        'client_approved_quote',
        'quote_requirement_waived',
        'quote_waiver_billing_amount',
        'quote_waiver_reason',
        'quote_waived_by',
        'quote_waived_at',
        'finance_released',
        'finance_released_at',
    ];

    protected $casts = [
        'date_received' => 'date',
        'expected_delivery_date' => 'date',
        'site_survey_skipped' => 'boolean',
        'assigned_po' => 'integer',
        'project_officer_id' => 'integer',
        'quote_approved' => 'boolean',
        'quote_approved_at' => 'datetime',
        'estimated_budget' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'assigned_users' => 'array',
        'selected_workflow_tasks' => 'array',
        'current_phase' => 'integer',
        'job_number' => 'string',
        'client_approved_quote' => 'decimal:2',
        'quote_requirement_waived' => 'boolean',
        'quote_waiver_billing_amount' => 'decimal:2',
        'quote_waived_at' => 'datetime',
        'finance_released' => 'boolean',
        'finance_released_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Department::class);
    }

    public function projectOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_officer_id');
    }

    public function assignedPo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_po');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'enquiry_id');
    }

    public function quoteApprovals(): HasMany
    {
        return $this->hasMany(QuoteApproval::class, 'enquiry_id');
    }

    /**
     * Overtime entries logged against this enquiry. OTEntry.project_id references
     * project_enquiries (see OTEntry::project()), so labour-allocation reporting
     * aggregates through this relation.
     */
    public function otEntries()
    {
        return $this->hasMany(\App\Modules\HR\Models\OTEntry::class, 'project_id');
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::saved(function ($enquiry) {
            if (!$enquiry->wasRecentlyCreated && !$enquiry->wasChanged('project_scope')) {
                return;
            }

            // getRawOriginal() reads $this->original, which for newly created models is
            // empty until syncOriginalAttributes() runs — which happens AFTER saved fires.
            // getAttributes() reads $this->attributes, always set by setProjectScopeAttribute.
            $raw = $enquiry->getAttributes()['project_scope'] ?? null;
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existingUuids = [];
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            $classification = $item['classification'] ?? 'PRE-DEFINED';
                            $name = $item['name'] ?? 'Untitled';
                            $status = $item['status'] ?? 'original';
                            $uuid = $item['uuid'] ?? $item['id'] ?? \Illuminate\Support\Str::uuid()->toString();
                        } else {
                            $classification = 'PRE-DEFINED';
                            $name = $item;
                            $status = 'original';
                            $uuid = \Illuminate\Support\Str::uuid()->toString();

                            $parts = array_map('trim', explode('|', $item));
                            $mainPart = $parts[0];

                            if (preg_match('/^\[(.*?)\]\s*(.*)$/', $mainPart, $matches)) {
                                $classification = trim($matches[1]);
                                $name = trim($matches[2]);
                            }

                            foreach ($parts as $part) {
                                if (str_starts_with($part, 'status:')) {
                                    $status = trim(str_replace('status:', '', $part));
                                } elseif (str_starts_with($part, 'id:')) {
                                    $uuid = trim(str_replace('id:', '', $part));
                                }
                            }
                        }

                        $existingUuids[] = $uuid;

                        $enquiry->deliverables()->updateOrCreate(
                            ['uuid' => $uuid],
                            [
                                'name' => $name,
                                'classification' => strtoupper($classification),
                                'status' => $status
                            ]
                        );
                    }
                    $enquiry->deliverables()->whereNotIn('uuid', $existingUuids)->delete();
                }
            }
        });
    }

    /**
     * Get the deliverables for the enquiry.
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(ProjectDeliverable::class, 'enquiry_id');
    }

    /**
     * Get the project scope as structured arrays (with raw string fallback).
     */
    public function getProjectScopeAttribute(): array
    {
        return $this->deliverables->map(function ($d) {
            return [
                'id'             => $d->uuid,
                'uuid'           => $d->uuid,
                // Real project_deliverables.id, for modules (e.g. Design) that need
                // to link back to this row via a foreign key. 'id' above is kept as
                // the uuid for backward compatibility with existing consumers.
                'deliverable_id' => $d->id,
                'name'           => $d->name,
                'classification' => $d->classification,
                'status'         => $d->status,
                'raw'            => "[{$d->classification}] {$d->name} | status:{$d->status} | id:{$d->uuid}",
            ];
        })->toArray();
    }

    /**
     * Set the project scope and sync deliverables.
     */
    public function setProjectScopeAttribute($value)
    {
        $items = [];
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            if (str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            } else {
                $items = preg_split('/\s*\|\s*(?=\[[^\]]+\])/', $value);
            }
        }

        // Only trim if items are strings, otherwise keep them as is (e.g. structured arrays)
        $processedItems = array_map(function($item) {
            return is_string($item) ? trim($item) : $item;
        }, $items);
        
        $processedItems = array_filter($processedItems, function($item) {
            if (is_string($item)) return !empty($item);
            return !empty($item);
        });

        $this->attributes['project_scope'] = json_encode(array_values($processedItems));
    }

    public function enquiryTasks(): HasMany
    {
        return $this->hasMany(\App\Modules\Projects\Models\EnquiryTask::class, 'project_enquiry_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(\App\Modules\Production\Models\WorkOrder::class, 'project_enquiry_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EnquiryPayment::class, 'project_enquiry_id');
    }


    // Scopes
    public function scopeByDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeAccessibleByUser($query, $user)
    {
        $accessibleDepartments = $user->getAccessibleDepartments()->pluck('id')->toArray();

        // Allow enquiries without department assignment, or with accessible departments
        return $query->where(function ($q) use ($accessibleDepartments) {
            $q->whereNull('department_id')
              ->orWhereIn('department_id', $accessibleDepartments);
        });
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', EnquiryConstants::getActiveStatuses());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', EnquiryConstants::STATUS_COMPLETED);
    }


    /**
     * Generate a unique project ID
     */
    public function generateProjectId(): string
    {
        return app(\App\Modules\Projects\Services\SequencingService::class)->generateProjectId($this->workflow_preset_type);
    }

    /**
     * Generate a unique job number when quote is approved
     */
    public function generateJobNumber(): string
    {
        return app(\App\Modules\Projects\Services\SequencingService::class)->generateJobNumber($this->workflow_preset_type);
    }

}
