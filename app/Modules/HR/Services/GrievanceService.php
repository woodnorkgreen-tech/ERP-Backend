<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Grievance;
use App\Modules\HR\Models\GrievanceComment;
use App\Modules\HR\Models\GrievanceActivityLog;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GrievanceService
{
    public function createGrievance(array $data): Grievance
    {
        $grievance = DB::transaction(function () use ($data) {
            $user = auth()->user();

            $grievance = Grievance::create([
                'complainant_id' => $user->id,
                'against_id' => $data['against_id'] ?? null,
                'description' => $data['description'],
                'category' => $data['category'] ?? null,
                'date_reported' => now(),
                'status' => Grievance::STATUS_REPORTED,
                'witnesses' => $data['witnesses'] ?? null,
                'attachments' => $data['attachments'] ?? null,
            ]);

            GrievanceActivityLog::log(
                $grievance->id,
                'Grievance filed',
                'Grievance filed by ' . ($user?->name ?? 'Unknown')
            );

            return $grievance;
        });

        $this->notifyGrievance($grievance, 'grievance_reported', 'Grievance reported',
            'A grievance has been submitted and requires review.', notifyHr: true);

        return $grievance;
    }

    public function getGrievances(Request $request): LengthAwarePaginator
    {
        $user = auth()->user();
        $query = Grievance::with([
            'complainant.employee.department',
            'against.employee.department',
            'resolver',
        ]);

        $this->applyAccessScope($query, $user);

        if ($request->filled('status')) {
            $query->status($request->status);
        }
        if ($request->filled('complainant_id')) {
            $query->where('complainant_id', $request->complainant_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('witnesses', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('complainant', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('against', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        if ($request->filled('department_id')) {
            $query->whereHas('complainant.employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        return $query->orderBy('date_reported', 'desc')
                      ->paginate($request->per_page ?? 15);
    }

    public function getGrievanceById(int $id): Grievance
    {
        return Grievance::with([
                'complainant.employee.department',
                'against.employee.department',
                'resolver',
                'comments.user',
                'activityLogs',
            ])
                        ->findOrFail($id);
    }

    public function updateGrievance(Grievance $grievance, array $data): Grievance
    {
        $originalStatus = $grievance->status;
        $grievance = DB::transaction(function () use ($grievance, $data) {
            $originalStatus = $grievance->status;

            $grievance->update($data);

            if (isset($data['status']) && $data['status'] !== $originalStatus) {
                GrievanceActivityLog::log(
                    $grievance->id,
                    'Status changed',
                    "Status changed from {$originalStatus} to {$data['status']}"
                );
            }

            return $grievance;
        });

        if (isset($data['status']) && $data['status'] !== $originalStatus) {
            $this->notifyGrievance($grievance, 'grievance_status_changed', 'Grievance status updated',
                "Your grievance status is now {$grievance->status}.");
        }

        return $grievance;
    }

    public function resolveGrievance(Grievance $grievance, string $resolution, ?string $notes = null): Grievance
    {
        $grievance = DB::transaction(function () use ($grievance, $resolution, $notes) {
            $user = auth()->user();

            $updates = [
                'status' => Grievance::STATUS_RESOLVED,
                'resolution' => $resolution,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ];

            if ($notes) {
                $updates['investigation_notes'] = $notes;
            }

            $grievance->update($updates);

            GrievanceActivityLog::log(
                $grievance->id,
                'Grievance resolved',
                'Resolution: ' . $resolution
            );

            return $grievance;
        });

        $this->notifyGrievance($grievance, 'grievance_resolved', 'Grievance resolved',
            'Your grievance has been resolved. Open the record to review the outcome.');

        return $grievance;
    }

    public function escalateGrievance(Grievance $grievance, string $to): Grievance
    {
        $grievance = DB::transaction(function () use ($grievance, $to) {
            $grievance->update([
                'status' => Grievance::STATUS_ESCALATED,
                'escalated_to' => $to,
            ]);

            GrievanceActivityLog::log(
                $grievance->id,
                'Grievance escalated',
                'Escalated to: ' . str_replace('_', ' ', $to)
            );

            return $grievance;
        });

        $this->notifyGrievance($grievance, 'grievance_escalated', 'Grievance escalated',
            'A grievance has been escalated and requires attention.', notifyHr: true);

        return $grievance;
    }

    public function addComment(Grievance $grievance, string $comment): GrievanceComment
    {
        $user = auth()->user();

        $grievanceComment = GrievanceComment::create([
            'grievance_id' => $grievance->id,
            'user_id' => $user->id,
            'comment' => $comment,
        ]);

        GrievanceActivityLog::log(
            $grievance->id,
            'Comment added',
            'Comment added by ' . $user->name
        );

        return $grievanceComment;
    }

    public function getGrievanceStatistics(): array
    {
        $user = auth()->user();
        $query = Grievance::query();

        $this->applyAccessScope($query, $user);

        return [
            'total' => $query->count(),
            'open' => (clone $query)->where('status', Grievance::STATUS_REPORTED)->count()
                + (clone $query)->where('status', Grievance::STATUS_INVESTIGATING)->count(),
            'investigating' => (clone $query)->where('status', Grievance::STATUS_INVESTIGATING)->count(),
            'resolved' => (clone $query)->where('status', Grievance::STATUS_RESOLVED)->count(),
            'escalated' => (clone $query)->where('status', Grievance::STATUS_ESCALATED)->count(),
            'closed' => (clone $query)->where('status', Grievance::STATUS_CLOSED)->count(),
        ];
    }

    protected function applyAccessScope($query, ?User $user): void
    {
        if (!$user) {
            $query->whereNull('id');
            return;
        }

        if ($user->hasAnyRole(['Super Admin', 'Admin', 'HR'])) {
            return;
        }

        // Dept lead: see own grievances + dept members' grievances, but NEVER ones filed against them
        if ($user->isDeptLead()) {
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $query->where(function ($q) use ($user, $accessibleDeptIds) {
                $q->where('complainant_id', $user->id);
                if (!empty($accessibleDeptIds)) {
                    $q->orWhere(function ($inner) use ($user, $accessibleDeptIds) {
                        $inner->whereHas('complainant', function ($userQ) use ($accessibleDeptIds) {
                            $userQ->whereIn('department_id', $accessibleDeptIds);
                        })
                        ->where(function ($notAccused) use ($user) {
                            $notAccused->whereNull('against_id')
                                       ->orWhere('against_id', '!=', $user->id);
                        });
                    });
                }
            });
            return;
        }

        // Regular employees see only what they filed
        $query->where('complainant_id', $user->id);
    }

    private function notifyGrievance(
        Grievance $grievance,
        string $type,
        string $title,
        string $message,
        bool $notifyHr = false,
    ): void {
        $grievance->loadMissing('complainant');

        NotificationService::send(
            type: $type,
            title: $title,
            message: $message,
            module: 'hr',
            data: [
                'url' => '/hr/grievance',
                'record_type' => 'grievance',
                'record_id' => $grievance->id,
                'status' => $grievance->status,
                'actor_id' => auth()->id(),
            ],
            users: [$grievance->complainant],
            role: $notifyHr ? ['Super Admin', 'HR'] : [],
        );
    }
}
