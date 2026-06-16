<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Grievance;
use App\Modules\HR\Models\Incident;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SelfServiceController extends Controller
{
    /**
     * Submit a profile update request.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $employee = auth()->user()->employee; 
        
        if (!$employee) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'new_data' => 'required|array',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only allow specific safe fields
        $allowedFields = [
            'first_name', 'last_name', 'date_of_birth',
            'id_number', 'kra_pin', 'nssf_id', 'nhif_id',
            'phone', 'email', 'address',
            'bank_name', 'bank_branch', 'bank_code', 'account_number', 'payment_method',
            'emergency_name', 'emergency_relationship', 'emergency_phone'
        ];

        $inputData = $request->input('new_data', []);
        $newData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $inputData)) {
                $newData[$field] = $inputData[$field];
            }
        }

        if (empty($newData)) {
            return response()->json(['message' => 'No valid fields provided for update.'], 422);
        }

        // Transform flat emergency fields to the expected JSON array structure
        if (array_key_exists('emergency_name', $newData) || array_key_exists('emergency_relationship', $newData) || array_key_exists('emergency_phone', $newData)) {
            $newData['emergency_contact'] = [
                'name' => $newData['emergency_name'] ?? null,
                'relationship' => $newData['emergency_relationship'] ?? null,
                'phone' => $newData['emergency_phone'] ?? null,
            ];
            unset($newData['emergency_name'], $newData['emergency_relationship'], $newData['emergency_phone']);
        }

        $requestRecord = ProfileUpdateRequest::create([
            'employee_id' => $employee->id,
            'requested_data' => $newData,
            'reason' => $request->reason,
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Profile update request submitted for HR approval',
            'data' => $requestRecord
        ], 201);
    }

    /**
     * Aggregated activity feed for the authenticated employee.
     *
     * Pulls the employee's most recent events across the self-service
     * domains (leave, salary advances, profile updates, overtime, grievances,
     * incidents), normalises them into a single shape and returns the latest
     * few ordered by recency.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user->employee;

        $limit = (int) $request->integer('limit', 8);
        $limit = max(1, min($limit, 25));

        $items = collect();

        if ($employee) {
            LeaveRequest::with('leaveType')
                ->where('employee_id', $employee->id)
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (LeaveRequest $leave) use ($items) {
                    $type = $leave->leaveType?->name ?? 'Leave';
                    $items->push($this->makeItem(
                        category: 'leave',
                        title: $type,
                        description: trim(sprintf('%s%s', $leave->date_range_label ? $leave->date_range_label . ' · ' : '', $leave->reason ?: 'Leave request')),
                        status: $leave->status,
                        timestamp: $leave->approved_at ?? $leave->updated_at ?? $leave->created_at,
                    ));
                });

            SalaryAdvanceRequest::where('employee_id', $employee->id)
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (SalaryAdvanceRequest $advance) use ($items) {
                    $items->push($this->makeItem(
                        category: 'advance',
                        title: 'Salary Advance',
                        description: trim(sprintf('KES %s · %s', number_format((float) $advance->amount), $advance->reason ?: 'Advance request')),
                        status: $advance->status,
                        timestamp: $advance->updated_at ?? $advance->created_at,
                    ));
                });

            ProfileUpdateRequest::where('employee_id', $employee->id)
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (ProfileUpdateRequest $update) use ($items) {
                    $fields = is_array($update->requested_data) ? array_keys($update->requested_data) : [];
                    $changed = $fields ? implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), array_slice($fields, 0, 3))) : 'profile details';
                    $items->push($this->makeItem(
                        category: 'profile',
                        title: 'Profile Update',
                        description: 'Requested change to ' . $changed,
                        status: $update->status,
                        timestamp: $update->reviewed_at ?? $update->updated_at ?? $update->created_at,
                    ));
                });

            OTEntry::where('employee_id', $employee->id)
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (OTEntry $entry) use ($items) {
                    $items->push($this->makeItem(
                        category: 'overtime',
                        title: sprintf('Overtime · %sh', rtrim(rtrim((string) $entry->hours, '0'), '.')),
                        description: trim(sprintf('%s%s', $entry->work_date ? $entry->work_date->format('M j') . ' · ' : '', $entry->job_title ?: 'Overtime entry')),
                        status: $entry->status,
                        timestamp: $entry->hr_approved_at ?? $entry->supervisor_approved_at ?? $entry->updated_at ?? $entry->created_at,
                    ));
                });
        }

        // Grievances and incidents are keyed by the user account, not the employee record.
        Grievance::where('complainant_id', $user->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Grievance $grievance) use ($items) {
                $items->push($this->makeItem(
                    category: 'grievance',
                    title: 'Grievance',
                    description: $grievance->category ? $grievance->category . ' grievance' : 'Grievance filed',
                    status: $grievance->status,
                    timestamp: $grievance->resolved_at ?? $grievance->updated_at ?? $grievance->created_at,
                ));
            });

        Incident::where('reported_by', $user->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Incident $incident) use ($items) {
                $items->push($this->makeItem(
                    category: 'incident',
                    title: $incident->title ?: 'Incident Report',
                    description: trim(sprintf('%s%s', $incident->severity ? $incident->severity . ' severity · ' : '', 'Incident reported')),
                    status: $incident->status,
                    timestamp: $incident->reviewed_at ?? $incident->updated_at ?? $incident->created_at,
                ));
            });

        $data = $items
            ->filter(fn ($item) => $item['timestamp'] !== null)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->map(function ($item) {
                $item['time_label'] = $item['timestamp']->diffForHumans();
                $item['timestamp'] = $item['timestamp']->toIso8601String();
                return $item;
            })
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Normalise a domain record into a feed item with a semantic state
     * used by the frontend for colour mapping.
     */
    private function makeItem(string $category, string $title, string $description, ?string $status, $timestamp): array
    {
        $status = $status ?: 'unknown';

        return [
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'status' => ucwords(str_replace('_', ' ', $status)),
            'state' => $this->statusState($status),
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Map a raw status string to a semantic state: success | pending | danger | info.
     */
    private function statusState(string $status): string
    {
        $s = strtolower($status);

        return match (true) {
            in_array($s, ['approved', 'resolved', 'closed', 'paid', 'completed'], true) => 'success',
            in_array($s, ['rejected', 'cancelled', 'canceled', 'recalled', 'escalated'], true) => 'danger',
            in_array($s, ['pending', 'reported', 'investigating', 'submitted', 'open', 'in progress', 'under review'], true) => 'pending',
            default => 'info',
        };
    }
}
