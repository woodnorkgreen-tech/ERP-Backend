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
            ['score' => $score, 'breakdown' => $breakdown] = $this->scoreCandidate($candidate, $criteria);
            return [
                'candidate_id' => $candidate->id,
                'name'         => $candidate->first_name . ' ' . $candidate->last_name,
                'email'        => $candidate->email,
                'score'        => $score,
                'breakdown'    => $breakdown,
                'would_pass'   => $score >= $threshold,
            ];
        })->toArray();
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
            ['score' => $score, 'breakdown' => $breakdown] = $this->scoreCandidate($candidate, $criteria);

            $newStatus = $score >= $threshold ? 'Shortlisted' : 'New';

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
            $required = array_map('strtolower', $criteria['skills']['value']);
            $weight   = (int) ($criteria['skills']['weight'] ?? 20);
            $totalPoints += $weight;

            $candidateSkills = array_map('strtolower', $candidate->skills ?? []);
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
            $required = array_map('strtolower', $criteria['certifications']['value']);
            $weight   = (int) ($criteria['certifications']['weight'] ?? 15);
            $totalPoints += $weight;

            $candidateCerts = array_map('strtolower', $candidate->certifications ?? []);
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
            $required = array_map('strtolower', $criteria['software']['value']);
            $weight   = (int) ($criteria['software']['weight'] ?? 10);
            $totalPoints += $weight;

            $candidateSoftware = array_map('strtolower', $candidate->software_proficiency ?? []);
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

        return ['score' => $score, 'breakdown' => $breakdown];
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
            $level = $levels[$edu->level_of_study] ?? 0;
            if ($level > $highest) $highest = $level;
        }
        return $highest;
    }

    private function getHighestEducationLabelFromLevel(int $level, array $levels): string
    {
        $flipped = array_flip($levels);
        return $flipped[$level] ?? 'N/A';
    }
}
