<?php

namespace App\Modules\Design\Support;

class DesignPauseReason
{
    public const VALUES = [
        'waiting_client_feedback', 'waiting_information_assets', 'internal_review',
        'switched_task', 'break', 'end_of_day', 'other',
    ];
}
