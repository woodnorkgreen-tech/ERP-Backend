<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\DisciplinaryCase;
use App\Modules\HR\Models\DisciplinaryComment;
use App\Modules\HR\Models\DisciplinaryActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DisciplineService
{
    public function createDisciplinaryCase(array $data): DisciplinaryCase
    {
        return DB::transaction(function () use ($data) {
            $user = auth()->user();

            $case = DisciplinaryCase::create([
                'employee_id' => $data['employee_id'],
                'reported_by' => $user->id,
                'allegations' => $data['allegations'],
                'offense_category' => $data['offense_category'],
                'date_reported' => now(),
                'status' => DisciplinaryCase::STATUS_REPORTED,
                'witnesses' => $data['witnesses'] ?? null,
                'attachments' => $data['attachments'] ?? null,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Disciplinary case reported',
                'Case reported by ' . $user->name
            );

            return $case;
        });
    }

    public function getDisciplinaryCases(Request $request): LengthAwarePaginator
    {
        $user = auth()->user();
        $query = DisciplinaryCase::with(['employee', 'reporter']);

        $this->applyAccessScope($query, $user);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('department_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        return $query->orderBy('date_reported', 'desc')
                      ->paginate($request->per_page ?? 15);
    }

    public function getDisciplinaryCaseById(int $id): DisciplinaryCase
    {
        return DisciplinaryCase::with(['employee', 'reporter', 'comments.user', 'activityLogs'])
                               ->findOrFail($id);
    }

    public function issueShowCause(DisciplinaryCase $case, string $letter): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $letter) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_SHOW_CAUSE_ISSUED,
                'show_cause_issued' => true,
                'show_cause_letter' => $letter,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Show cause issued',
                'Show cause letter issued, response required within 48 hours'
            );

            return $case;
        });
    }

    public function submitShowCauseResponse(DisciplinaryCase $case, string $response): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $response) {
            $case->update([
                'show_cause_response' => $response,
                'show_cause_response_date' => now(),
                'status' => DisciplinaryCase::STATUS_INVESTIGATING,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Show cause response submitted',
                'Employee submitted response to show cause'
            );

            return $case;
        });
    }

    public function scheduleHearing(DisciplinaryCase $case, string $date, array $panel): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $date, $panel) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_HEARING_SCHEDULED,
                'hearing_scheduled' => true,
                'hearing_date' => $date,
                'hearing_panel' => $panel,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Hearing scheduled',
                'Hearing scheduled for ' . $date . ' with ' . count($panel) . ' panel members'
            );

            return $case;
        });
    }

    public function submitHearingMinutes(DisciplinaryCase $case, string $minutes, string $decision): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $minutes, $decision) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_HEARING_HELD,
                'hearing_minutes' => $minutes,
                'hearing_decision' => $decision,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Hearing held',
                'Hearing minutes submitted, decision: ' . $decision
            );

            return $case;
        });
    }

    public function issueWarning(DisciplinaryCase $case, string $type, ?string $letter = null): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $type, $letter) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_DECISION_MADE,
                'warning_issued' => $type,
                'warning_letter' => $letter,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Warning issued',
                'Warning type: ' . $type
            );

            return $case;
        });
    }

    public function submitAppeal(DisciplinaryCase $case, string $details): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $details) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_APPEALED,
                'appeal_submitted' => true,
                'appeal_details' => $details,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Appeal submitted',
                'Appeal submitted against the case decision'
            );

            return $case;
        });
    }

    public function finalizeCase(DisciplinaryCase $case, string $decision): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $decision) {
            $case->update([
                'status' => DisciplinaryCase::STATUS_FINAL,
                'final_decision' => $decision,
            ]);

            DisciplinaryActivityLog::log(
                $case->id,
                'Case finalized',
                'Final decision: ' . $decision
            );

            return $case;
        });
    }

    public function addComment(DisciplinaryCase $case, string $comment): DisciplinaryComment
    {
        $user = auth()->user();

        $caseComment = DisciplinaryComment::create([
            'case_id' => $case->id,
            'user_id' => $user->id,
            'comment' => $comment,
        ]);

        DisciplinaryActivityLog::log(
            $case->id,
            'Comment added',
            'Comment added by ' . $user->name
        );

        return $caseComment;
    }

    public function getDisciplineStatistics(): array
    {
        $user = auth()->user();
        $query = DisciplinaryCase::query();

        $this->applyAccessScope($query, $user);

        return [
            'total' => $query->count(),
            'active' => (clone $query)->whereNotIn('status', [
                DisciplinaryCase::STATUS_FINAL,
            ])->count(),
            'hearings_scheduled' => (clone $query)->where('status', DisciplinaryCase::STATUS_HEARING_SCHEDULED)->count(),
            'warnings_issued' => (clone $query)->where('status', DisciplinaryCase::STATUS_DECISION_MADE)->count(),
            'terminations' => (clone $query)->where('warning_issued', 'termination')->count(),
            'appeals' => (clone $query)->where('appeal_submitted', true)->count(),
        ];
    }

    protected function applyAccessScope($query, ?User $user): void
    {
        if (!$user) {
            $query->whereNull('id');
            return;
        }

        // Only HR and Super Admin can access disciplinary cases
        if ($user->hasAnyRole(['Super Admin', 'HR'])) {
            return;
        }

        // All other roles are denied access
        $query->whereNull('id');
    }
}