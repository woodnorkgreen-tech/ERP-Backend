<?php

namespace App\Modules\Printing\Support;

class PrintPauseReason
{
    public const VALUES = [
        'waiting_materials', 'machine_fault_maintenance', 'artwork_issue',
        'switched_job', 'break', 'end_of_day', 'client_artwork_changes', 'other',
    ];
}
