<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Grievance;
use App\Modules\HR\Models\GrievanceComment;
use App\Modules\HR\Models\GrievanceActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GrievanceService
{
    public function createGrievance(array $data): Grievance
    {
        return DB::transaction(function () use ($data) {
            $user = auth()->user();

            $grievance = Grievance::create([
                'complainant_id' => $data['complainant_id'] ?? ($user ? $user->id : null),
                'against_id' => $data['against_id'] ?? null,
                'description' => $data['description'],
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
    }

    public function getGrievances(Request $request): LengthAwarePaginator
    {
        $user = auth()->user();
        $query = Grievance::with(['complainant', 'against', 'resolver']);

        $this->applyAccessScope($query, $user);

        if ($request->filled('status')) {
            $query->status($request->status);
        }
        if ($request->filled('complainant_id')) {
            $query->where('complainant_id', $request->complainant_id);
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
        return Grievance::with(['complainant', 'against', 'resolver', 'comments.user', 'activityLogs'])
                        ->findOrFail($id);
    }

    public function updateGrievance(Grievance $grievance, array $data): Grievance
    {
        return DB::transaction(function () use ($grievance, $data) {
            $grievance->update($data);
            return $grievance;
        });
    }

    public function resolveGrievance(Grievance $grievance, string $resolution): Grievance
    {
        return DB::transaction(function () use ($grievance, $resolution) {
            $user = auth()->user();

            $grievance->update([
                'status' => Grievance::STATUS_RESOLVED,
                'resolution' => $resolution,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);

            GrievanceActivityLog::log(
                $grievance->id,
                'Grievance resolved',
                'Resolution: ' . $resolution
            );

            return $grievance;
        });
    }

    public function escalateGrievance(Grievance $grievance, string $to): Grievance
    {
        return DB::transaction(function () use ($grievance, $to) {
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

        // Only HR and Super Admin can access grievances
        if ($user->hasAnyRole(['Super Admin', 'HR'])) {
            return;
        }

        // All other roles are denied access
        $query->whereNull('id');
    }
}