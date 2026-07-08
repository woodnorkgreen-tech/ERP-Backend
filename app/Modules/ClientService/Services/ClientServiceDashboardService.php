<?php

namespace App\Modules\ClientService\Services;

use App\Modules\ClientService\Models\Client;
use App\Models\ProjectEnquiry;
use App\Models\PublicLead;
use App\Constants\EnquiryConstants;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ClientServiceDashboardService
{
    /**
     * Get aggregated dashboard statistics
     */
    public function getDashboardStats(): array
    {
        // 1. Total Clients
        $totalClients = Client::count();

        // 2. Active Projects (from ProjectEnquiry)
        // Aligned with ProjectsDashboardService.php logic
        $activeStatuses = [
            'planning',
            'in_progress',
            'materials_specified',
            'budget_created',
            'quote_approved'
        ];
        $activeProjectsCount = ProjectEnquiry::whereIn('status', $activeStatuses)->count();

        // 3. New Enquiries (Logged in the current month)
        $newEnquiriesCount = ProjectEnquiry::where('status', 'enquiry_logged')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        // 4. Conversion Rate
        $totalEnquiries = ProjectEnquiry::count();
        // Converted are those with a job_number or the specific status
        $convertedEnquiries = ProjectEnquiry::where(function($query) {
                $query->whereNotNull('job_number')
                      ->orWhere('status', 'converted_to_project');
            })->count();
        $conversionRate = $totalEnquiries > 0 ? round(($convertedEnquiries / $totalEnquiries) * 100, 1) : 0;

        // 4b. Active Leads (public leads not yet actioned/converted)
        $activeLeadsCount = PublicLead::where('status', 'new')->count();

        // 4c. Pending Feedback (completed projects with no submitted handover survey)
        $pendingFeedbackCount = ProjectEnquiry::where('status', EnquiryConstants::STATUS_COMPLETED)
            ->whereDoesntHave('enquiryTasks.handoverSurvey', function ($q) {
                $q->where('submitted', true);
            })
            ->count();

        // 5. Lead Source Distribution
        $leadSources = Client::select('lead_source', DB::raw('count(*) as count'))
            ->whereNotNull('lead_source')
            ->groupBy('lead_source')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->lead_source => $item->count];
            })->toArray();

        return [
            'total_clients' => $totalClients,
            'active_projects_count' => $activeProjectsCount,
            'active_leads_count' => $activeLeadsCount,
            'pending_feedback_count' => $pendingFeedbackCount,
            'new_enquiries_count' => $newEnquiriesCount,
            'conversion_rate' => $conversionRate,
            'total_enquiries' => $totalEnquiries,
            'converted_enquiries_count' => $convertedEnquiries,
            'lead_sources' => $leadSources,
        ];
    }

    /**
     * Get combined activity feed
     */
    public function getActivityFeed(int $limit = 10): array
    {
        // Get recent enquiries
        $enquiries = ProjectEnquiry::with('client')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'type' => 'enquiry',
                    'title' => $e->title,
                    'status' => $e->status,
                    'priority' => $e->priority,
                    'created_at' => $e->created_at->toISOString(),
                    'client_name' => $e->client->full_name ?? 'Anonymous Client',
                ];
            });

        // Get recent leads
        $leads = PublicLead::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($l) {
                return [
                    'id' => $l->id,
                    'type' => 'lead',
                    'title' => "New Lead: " . ($l->company_name ?? $l->full_name),
                    'status' => $l->status,
                    'priority' => 'medium', // Leads usually have a default priority
                    'created_at' => $l->created_at->toISOString(),
                    'client_name' => $l->full_name,
                ];
            });

        // Combine and sort
        return collect([...$enquiries, ...$leads])
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->toArray();
    }
}
