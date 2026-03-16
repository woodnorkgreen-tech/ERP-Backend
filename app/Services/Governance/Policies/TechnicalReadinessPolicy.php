<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Services\Governance\GateResult;
use App\Models\SiteSurvey;
use App\Models\DesignAsset;

class TechnicalReadinessPolicy extends BasePolicy
{
    protected function runPolicy(ProjectEnquiry $enquiry, array $context = []): GateResult
    {
        // 1. Check Site Survey Evidence
        if (!$enquiry->site_survey_skipped) {
            $survey = SiteSurvey::whereHas('enquiryTask', function ($q) use ($enquiry) {
                $q->where('project_enquiry_id', $enquiry->id)->where('type', 'site-survey');
            })->first();

            if (!$survey || empty($survey->survey_photos)) {
                return GateResult::blocked("Technical Gate: Site Survey evidence (photos) is required before proceeding.");
            }
        }

        // 2. Check Design Assets
        $designAssets = DesignAsset::whereHas('task', function ($q) use ($enquiry) {
            $q->where('project_enquiry_id', $enquiry->id)->where('type', 'design');
        })->count();

        if ($designAssets === 0) {
            return GateResult::blocked("Technical Gate: Approved Design assets or conceptual layouts are required.");
        }

        return GateResult::authorized();
    }
}
