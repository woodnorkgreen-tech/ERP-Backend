<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Candidate;
use Carbon\Carbon;

class AutoShortlistService
{
    /**
     * Score all New candidates for a job without saving anything.
     * Returns an array of { candidate_id, name, email, score, breakdown, would_pass }
     */
    public function preview(JobPosting $job, array $criteria, int $threshold): array
    {
        $candidates = Candidate::with(['experiences', 'educations'])
            ->where('job_posting_id', $job->id)
            ->whereIn('status', ['New', 'Shortlisted']) // include already-shortlisted so re-run works
            ->get();

        return $candidates->map(function ($candidate) use ($criteria, $threshold) {
            ['score' => $score, 'breakdown' => $breakdown, 'disqualified' => $disqualified] = $this->scoreCandidate($candidate, $criteria);
            return [
                'candidate_id' => $candidate->id,
                'name'         => $candidate->first_name . ' ' . $candidate->last_name,
                'email'        => $candidate->email,
                'current_status' => $candidate->status,
                'score'        => $score,
                'breakdown'    => $breakdown,
                'disqualified' => $disqualified,
                'would_pass'   => ! $disqualified && $score >= $threshold,
            ];
        })->sortByDesc('score')->values()->toArray();
    }

    /**
     * Apply shortlisting: score all New+Shortlisted candidates, promote/demote as needed.
     * Saves score + breakdown on every candidate.
     * Returns summary: { shortlisted, returned_to_new, total_scored }
     */
    public function apply(JobPosting $job, array $criteria, int $threshold): array
    {
        $candidates = Candidate::with(['experiences', 'educations'])
            ->where('job_posting_id', $job->id)
            ->whereIn('status', ['New', 'Shortlisted'])
            ->get();

        $shortlisted    = 0;
        $returnedToNew  = 0;

        foreach ($candidates as $candidate) {
            ['score' => $score, 'breakdown' => $breakdown, 'disqualified' => $disqualified] = $this->scoreCandidate($candidate, $criteria);

            $newStatus = (! $disqualified && $score >= $threshold) ? 'Shortlisted' : 'New';

            if ($newStatus === 'Shortlisted' && $candidate->status !== 'Shortlisted') $shortlisted++;
            if ($newStatus === 'New' && $candidate->status === 'Shortlisted') $returnedToNew++;

            $candidate->update([
                'shortlist_score'     => $score,
                'shortlist_breakdown' => $breakdown,
                'status'              => $newStatus,
            ]);
        }

        return [
            'shortlisted'    => $shortlisted,
            'returned_to_new'=> $returnedToNew,
            'total_scored'   => $candidates->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // SCORING ENGINE
    // ─────────────────────────────────────────────────────────────

    private function scoreCandidate(Candidate $candidate, array $criteria): array
    {
        $totalPoints  = 0;
        $earnedPoints = 0;
        $breakdown    = [];
        $disqualified = false;

        // ── 1. Min Experience Years ──────────────────────────────
        if (!empty($criteria['min_experience_years']['enabled'])) {
            $required = (int) ($criteria['min_experience_years']['value'] ?? 0);
            $weight   = (int) ($criteria['min_experience_years']['weight'] ?? 20);
            $totalPoints += $weight;

            $actualYears = $this->calculateTotalExperienceYears($candidate->experiences);

            if ($actualYears >= $required) {
                $earned = $weight;
            } elseif ($required > 0) {
                // Partial credit: proportional
                $earned = (int) round(($actualYears / $required) * $weight);
            } else {
                $earned = $weight;
            }

            $earnedPoints += $earned;
            $breakdown['experience'] = [
                'label'    => 'Min Experience',
                'required' => "{$required} yrs",
                'actual'   => round($actualYears, 1) . ' yrs',
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $actualYears >= $required,
            ];
        }

        // ── 2. Education Level ───────────────────────────────────
        if (!empty($criteria['education_level']['enabled'])) {
            $required = $criteria['education_level']['value'] ?? '';
            $weight   = (int) ($criteria['education_level']['weight'] ?? 20);
            $totalPoints += $weight;

            $levels = ['Certificate' => 1, 'Diploma' => 2, 'Degree' => 3, 'Masters' => 4, 'PhD' => 5];
            $requiredLevel = $levels[$required] ?? 0;
            $actualLevel   = $this->getHighestEducationLevel($candidate->educations, $levels);

            $passed = $actualLevel >= $requiredLevel;
            $earned = $passed ? $weight : 0;
            $earnedPoints += $earned;

            $breakdown['education_level'] = [
                'label'    => 'Education Level',
                'required' => $required,
                'actual'   => $this->getHighestEducationLabelFromLevel($actualLevel, $levels),
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $passed,
            ];
        }

        // ── 3. Field of Study ────────────────────────────────────
        if (!empty($criteria['field_of_study']['enabled'])) {
            $required = strtolower(trim((string) ($criteria['field_of_study']['value'] ?? '')));
            $weight   = (int) ($criteria['field_of_study']['weight'] ?? 10);
            $totalPoints += $weight;

            $passed = false;
            foreach ($candidate->educations as $edu) {
                if (!empty($required) && str_contains(strtolower($edu->field_of_study ?? ''), $required)) {
                    $passed = true;
                    break;
                }
            }

            $earned = $passed ? $weight : 0;
            $earnedPoints += $earned;

            $breakdown['field_of_study'] = [
                'label'    => 'Field of Study',
                'required' => $criteria['field_of_study']['value'] ?? '',
                'actual'   => $candidate->educations->pluck('field_of_study')->filter()->implode(', ') ?: 'N/A',
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $passed,
            ];
        }

        // ── 4. Required Skills ───────────────────────────────────
        if (!empty($criteria['skills']['enabled']) && !empty($criteria['skills']['value'])) {
            $required = $this->normalizeStringList($criteria['skills']['value']);
            $weight   = (int) ($criteria['skills']['weight'] ?? 20);
            $totalPoints += $weight;

            $candidateSkills = $this->normalizeStringList($candidate->skills ?? []);
            $matched = count(array_intersect($required, $candidateSkills));
            $total   = count($required);

            $earned = $total > 0 ? (int) round(($matched / $total) * $weight) : $weight;
            $earnedPoints += $earned;

            $breakdown['skills'] = [
                'label'    => 'Required Skills',
                'required' => implode(', ', $criteria['skills']['value']),
                'actual'   => "{$matched}/{$total} matched",
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $matched === $total,
            ];
        }

        // ── 5. Required Certifications ───────────────────────────
        if (!empty($criteria['certifications']['enabled']) && !empty($criteria['certifications']['value'])) {
            $required = $this->normalizeStringList($criteria['certifications']['value']);
            $weight   = (int) ($criteria['certifications']['weight'] ?? 15);
            $totalPoints += $weight;

            $candidateCerts = $this->normalizeStringList($candidate->certifications ?? []);
            $matched = count(array_intersect($required, $candidateCerts));
            $total   = count($required);

            $earned = $total > 0 ? (int) round(($matched / $total) * $weight) : $weight;
            $earnedPoints += $earned;

            $breakdown['certifications'] = [
                'label'    => 'Certifications',
                'required' => implode(', ', $criteria['certifications']['value']),
                'actual'   => "{$matched}/{$total} matched",
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $matched === $total,
            ];
        }

        // ── 6. Required Software ─────────────────────────────────
        if (!empty($criteria['software']['enabled']) && !empty($criteria['software']['value'])) {
            $required = $this->normalizeStringList($criteria['software']['value']);
            $weight   = (int) ($criteria['software']['weight'] ?? 10);
            $totalPoints += $weight;

            $candidateSoftware = $this->normalizeSoftwareList($candidate->software_proficiency ?? []);
            $matched = count(array_intersect($required, $candidateSoftware));
            $total   = count($required);

            $earned = $total > 0 ? (int) round(($matched / $total) * $weight) : $weight;
            $earnedPoints += $earned;

            $breakdown['software'] = [
                'label'    => 'Software Proficiency',
                'required' => implode(', ', $criteria['software']['value']),
                'actual'   => "{$matched}/{$total} matched",
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $matched === $total,
            ];
        }

        // ── 7. Questionnaire Rules ───────────────────────────────
        if (!empty($criteria['expected_salary_range']['enabled'])) {
            $min = $this->parseMoney($criteria['expected_salary_range']['min'] ?? null);
            $max = $this->parseMoney($criteria['expected_salary_range']['max'] ?? null);
            $responses = $candidate->questionnaire_responses ?? [];
            $actual = $this->parseMoney($responses['expected_salary'] ?? null);

            $passed = $actual !== null
                && ($min === null || $actual >= $min)
                && ($max === null || $actual <= $max);

            if (! $passed) {
                $disqualified = true;
            }

            $breakdown['expected_salary'] = [
                'label' => 'Expected Salary',
                'required' => $this->formatSalaryRange($min, $max),
                'actual' => $actual === null ? 'Not provided' : 'KES ' . number_format($actual),
                'earned' => $passed ? 1 : 0,
                'max' => 1,
                'passed' => $passed,
                'hard_gate' => true,
            ];
        }

        if (!empty($criteria['questionnaire_rules']['enabled']) && !empty($criteria['questionnaire_rules']['rules'])) {
            $rules  = $criteria['questionnaire_rules']['rules'];
            $weight = (int) ($criteria['questionnaire_rules']['weight'] ?? 5);
            $totalPoints += $weight;

            $responses = $candidate->questionnaire_responses ?? [];
            $matched   = 0;

            foreach ($rules as $rule) {
                $key      = $rule['key'] ?? '';
                $accepted = $rule['accepted_answers'] ?? [];
                if (!is_array($accepted)) {
                    $accepted = [$accepted];
                }
                if (empty($accepted) && !empty($rule['expected'])) {
                    $accepted = [(string) $rule['expected']];
                }

                $accepted = array_filter(array_map('strtolower', $accepted));
                $actual   = strtolower(trim($responses[$key] ?? ''));

                if ($actual !== '' && !empty($accepted) && in_array($actual, $accepted, true)) {
                    $matched++;
                }
            }

            $total  = count($rules);
            $earned = $total > 0 ? (int) round(($matched / $total) * $weight) : $weight;
            $earnedPoints += $earned;

            $breakdown['questionnaire'] = [
                'label'    => 'Questionnaire',
                'required' => implode(', ', array_map('ucfirst', array_filter(array_merge(...array_map(function ($rule) {
                    $accepted = $rule['accepted_answers'] ?? [];
                    if (!is_array($accepted)) {
                        $accepted = [$accepted];
                    }
                    if (empty($accepted) && !empty($rule['expected'])) {
                        $accepted = [(string) $rule['expected']];
                    }
                    return $accepted;
                }, $rules))))),
                'actual'   => "{$matched}/{$total} matched",
                'earned'   => $earned,
                'max'      => $weight,
                'passed'   => $matched === $total,
            ];
        }

        $score = $totalPoints > 0 ? (int) round(($earnedPoints / $totalPoints) * 100) : 0;

        return ['score' => $score, 'breakdown' => $breakdown, 'disqualified' => $disqualified];
    }

    // ─────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────

    private function calculateTotalExperienceYears($experiences): float
    {
        $totalMonths = 0;
        foreach ($experiences as $exp) {
            $start = Carbon::parse($exp->start_date);
            $end   = $exp->is_current ? Carbon::now() : ($exp->end_date ? Carbon::parse($exp->end_date) : Carbon::now());
            $totalMonths += $start->diffInMonths($end);
        }
        return round($totalMonths / 12, 1);
    }

    private function getHighestEducationLevel($educations, array $levels): int
    {
        $highest = 0;
        foreach ($educations as $edu) {
            $level = $levels[$this->normalizeEducationLevel($edu->level_of_study)] ?? 0;
            if ($level > $highest) $highest = $level;
        }
        return $highest;
    }

    private function getHighestEducationLabelFromLevel(int $level, array $levels): string
    {
        $flipped = array_flip($levels);
        return $flipped[$level] ?? 'N/A';
    }

    private function normalizeStringList($values): array
    {
        return collect((array) $values)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeSoftwareList($values): array
    {
        return collect((array) $values)
            ->map(function ($value) {
                if (is_array($value)) {
                    return $value['name'] ?? $value['software'] ?? $value['tool'] ?? null;
                }

                if (is_object($value)) {
                    return $value->name ?? $value->software ?? $value->tool ?? null;
                }

                return $value;
            })
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeEducationLevel(?string $value): string
    {
        $clean = strtolower(trim((string) $value));

        return match (true) {
            str_contains($clean, 'phd'), str_contains($clean, 'doctor') => 'PhD',
            str_contains($clean, 'master') => 'Masters',
            str_contains($clean, 'bachelor'), str_contains($clean, 'degree') => 'Degree',
            str_contains($clean, 'diploma') => 'Diploma',
            str_contains($clean, 'certificate') => 'Certificate',
            default => (string) $value,
        };
    }

    private function parseMoney($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : (int) $digits;
    }

    private function formatSalaryRange(?int $min, ?int $max): string
    {
        if ($min !== null && $max !== null) {
            return 'KES ' . number_format($min) . ' - KES ' . number_format($max);
        }

        if ($min !== null) {
            return 'At least KES ' . number_format($min);
        }

        if ($max !== null) {
            return 'Up to KES ' . number_format($max);
        }

        return 'Any salary';
    }
}
