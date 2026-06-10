<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PerformanceReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PerformanceReviewController
{
    public function index(Employee $employee): JsonResponse
    {
        $reviews = $employee->performanceReviews()
            ->with('reviewer:id,name,email')
            ->get();

        return response()->json($reviews);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'review_date'     => 'required|date',
            'period_start'    => 'nullable|date',
            'period_end'      => 'nullable|date|after_or_equal:period_start',
            'overall_rating'  => 'required|numeric|min:0|max:5',
            'notes'           => 'nullable|string|max:2000',
            'recommendations' => 'nullable|string|max:2000',
            'status'          => ['nullable', Rule::in(['draft', 'finalised'])],
        ]);

        $data['reviewed_by'] = auth()->id();

        $review = $employee->performanceReviews()->create($data);
        $review->load('reviewer:id,name,email');

        // Keep the employee's performance_rating field in sync with the latest finalised review
        if (($data['status'] ?? 'draft') === 'finalised') {
            $employee->update([
                'performance_rating' => $data['overall_rating'],
                'last_review_date'   => $data['review_date'],
            ]);
        }

        return response()->json($review, 201);
    }

    public function update(Request $request, Employee $employee, PerformanceReview $review): JsonResponse
    {
        abort_if($review->employee_id !== $employee->id, 404);

        $data = $request->validate([
            'review_date'     => 'sometimes|date',
            'period_start'    => 'nullable|date',
            'period_end'      => 'nullable|date',
            'overall_rating'  => 'sometimes|numeric|min:0|max:5',
            'notes'           => 'nullable|string|max:2000',
            'recommendations' => 'nullable|string|max:2000',
            'status'          => ['nullable', Rule::in(['draft', 'finalised'])],
        ]);

        $review->update($data);
        $review->load('reviewer:id,name,email');

        // Re-sync the employee summary rating if this is now the latest finalised review
        if (($data['status'] ?? $review->status) === 'finalised') {
            $latest = $employee->performanceReviews()
                ->where('status', 'finalised')
                ->latest('review_date')
                ->first();

            if ($latest) {
                $employee->update([
                    'performance_rating' => $latest->overall_rating,
                    'last_review_date'   => $latest->review_date,
                ]);
            }
        }

        return response()->json($review);
    }

    public function destroy(Employee $employee, PerformanceReview $review): JsonResponse
    {
        abort_if($review->employee_id !== $employee->id, 404);
        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }
}
