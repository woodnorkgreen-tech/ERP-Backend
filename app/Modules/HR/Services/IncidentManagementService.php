<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Incident;
use App\Modules\HR\Models\IncidentComment;
use App\Modules\HR\Models\IncidentActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Modules\HR\Notifications\IncidentCreatedNotification;
use App\Modules\HR\Notifications\IncidentStatusChangedNotification;

class IncidentManagementService
{
    /**
     * Create a new incident
     */
    public function createIncident(array $data): Incident
    {
        return DB::transaction(function () use ($data) {
            $user = auth()->user();
            
            $incident = Incident::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'],
                'incident_datetime' => $data['incident_datetime'],
                'severity' => $data['severity'] ?? 'Medium',
                'status' => Incident::STATUS_OPEN,
                'incident_types' => $data['incident_types'] ?? null,
                'classification_category' => $data['classification_category'] ?? null,
                'classification_subcategory' => $data['classification_subcategory'] ?? null,
                'classification_other_details' => $data['classification_other_details'] ?? null,
                'immediate_actions_taken' => $data['immediate_actions_taken'] ?? null,
                'witnesses' => $data['witnesses'] ?? null,
                'root_cause' => $data['root_cause'] ?? null,
                'corrective_actions' => $data['corrective_actions'] ?? null,
                'preventive_measures' => $data['preventive_measures'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
                'evidence_paths' => $data['evidence_paths'] ?? null,
                'reporter_name' => $data['reporter_name'] ?? ($user ? $user->name : null),
                'reporter_email' => $data['reporter_email'] ?? ($user ? $user->email : null),
                'reported_by' => $user ? $user->id : null,
                'department_id' => $data['department_id'] ?? ($user?->employee?->department_id ?? null),
                'job_title' => $data['job_title'] ?? ($user?->employee?->job_title ?? null),
                'contact_info' => $data['contact_info'] ?? ($user?->employee?->phone ?? null),
                'date_reported' => now(),
            ]);
            
            // Log activity
            IncidentActivityLog::log(
                $incident->id,
                'Incident created',
                "Incident reported by " . ($user?->name ?? 'Guest')
            );
            
            // Send notifications to HR/Admin
            $this->notifyAdministrators($incident);
            
            return $incident;
        });
    }
    
    /**
     * Update an incident
     */
    public function updateIncident(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $incident->update($data);
            
            // Log activity
            IncidentActivityLog::log(
                $incident->id,
                'Incident updated',
                'Incident details updated'
            );
            
            return $incident;
        });
    }
    
    /**
     * Review an incident (add review details)
     */
    public function reviewIncident(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $user = auth()->user();
            
            $updateData = [
                'short_term_fixes' => $data['short_term_fixes'] ?? null,
                'long_term_measures' => $data['long_term_measures'] ?? null,
                'responsible_party' => $data['responsible_party'] ?? null,
                'impact_analysis' => $data['impact_analysis'] ?? null,
                'avoid_recurrence' => $data['avoid_recurrence'] ?? null,
                'policy_changes' => $data['policy_changes'] ?? null,
                'training_needs' => $data['training_needs'] ?? null,
                'review_notes' => $data['review_notes'] ?? null,
                'status' => $data['status'] ?? $incident->status,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ];
            
            $incident->update($updateData);
            
            // Log activity
            IncidentActivityLog::log(
                $incident->id,
                'Incident reviewed',
                "Status changed to: " . $updateData['status']
            );
            
            // Notify reporter if status changed
            if ($incident->reported_by) {
                $this->notifyStatusChange($incident, $updateData['status']);
            }
            
            return $incident;
        });
    }
    
    /**
     * Approve an incident
     */
    public function approveIncident(Incident $incident): Incident
    {
        return DB::transaction(function () use ($incident) {
            $user = auth()->user();
            
            $incident->update([
                'approved_by' => $user->id,
                'approved_at' => now(),
                'status' => Incident::STATUS_CLOSED,
            ]);
            
            // Log activity
            IncidentActivityLog::log(
                $incident->id,
                'Incident approved',
                'Incident has been approved and closed'
            );
            
            // Notify reporter
            if ($incident->reported_by) {
                $this->notifyStatusChange($incident, 'Closed');
            }
            
            return $incident;
        });
    }
    
    /**
     * Delete an incident
     */
    public function deleteIncident(Incident $incident): bool
    {
        // Log activity before deletion
        IncidentActivityLog::log(
            $incident->id,
            'Incident deleted',
            'Incident has been deleted'
        );
        
        return $incident->delete();
    }
    
    /**
     * Get incidents with filters
     */
    public function getIncidents(Request $request): LengthAwarePaginator
    {
        $query = Incident::with(['reporter', 'department', 'reviewer']);
        
        // Apply filters
        if ($request->filled('status')) {
            $query->status($request->status);
        }
        
        if ($request->filled('severity')) {
            $query->severity($request->severity);
        }
        
        if ($request->filled('department_id')) {
            $query->department($request->department_id);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }
        
        // If user is not admin/hr, show only their own incidents
        $user = auth()->user();
        if ($user && !$user->hasAnyRole(['admin', 'hr_manager'])) {
            $query->reportedBy($user->id);
        }
        
        return $query->orderBy('date_reported', 'desc')
                     ->paginate($request->per_page ?? 15);
    }
    
    /**
     * Get incident by ID
     */
    public function getIncidentById(int $id): ?Incident
    {
        return Incident::with(['reporter', 'department', 'reviewer', 'approver', 'comments.user', 'activityLogs.user'])
                       ->findOrFail($id);
    }
    
    /**
     * Add comment to incident
     */
    public function addComment(Incident $incident, string $comment): IncidentComment
    {
        $user = auth()->user();
        
        $comment = IncidentComment::create([
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'comment' => $comment,
        ]);
        
        // Log activity
        IncidentActivityLog::log(
            $incident->id,
            'Comment added',
            'New comment added to incident'
        );
        
        return $comment;
    }
    
    /**
     * Get incident statistics
     */
    public function getStatistics(): array
    {
        $query = Incident::query();
        
        // If user is not admin/hr, show only their own stats
        $user = auth()->user();
        if ($user && !$user->hasAnyRole(['admin', 'hr_manager'])) {
            $query->reportedBy($user->id);
        }
        
        return [
            'total' => $query->count(),
            'open' => (clone $query)->where('status', Incident::STATUS_OPEN)->count(),
            'in_progress' => (clone $query)->where('status', Incident::STATUS_IN_PROGRESS)->count(),
            'under_review' => (clone $query)->where('status', Incident::STATUS_UNDER_REVIEW)->count(),
            'resolved' => (clone $query)->where('status', Incident::STATUS_RESOLVED)->count(),
            'closed' => (clone $query)->where('status', Incident::STATUS_CLOSED)->count(),
            'critical' => (clone $query)->whereIn('severity', ['High', 'Critical'])->count(),
        ];
    }
    
    /**
     * Notify administrators about new incident
     */
    protected function notifyAdministrators(Incident $incident): void
    {
        // Get HR admins and department heads
        $admins = User::role(['admin', 'hr_manager'])->get();
        
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new IncidentCreatedNotification($incident));
        }
    }
    
    /**
     * Notify reporter about status change
     */
    protected function notifyStatusChange(Incident $incident, string $newStatus): void
    {
        if ($incident->reporter) {
            Notification::send($incident->reporter, new IncidentStatusChangedNotification($incident, $newStatus));
        }
    }
}