<?php

namespace App\Modules\Teams\Services;

use App\Modules\Teams\Models\TeamsActivityLog;
use Illuminate\Support\Facades\Request;

class TeamsActivityLogService
{
    public function log(array $data): TeamsActivityLog
    {
        return TeamsActivityLog::create([
            'teams_task_id' => $data['teams_task_id'] ?? null,
            'teams_member_id' => $data['teams_member_id'] ?? null,
            'action' => $data['action'],
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'performed_by' => $data['performed_by'] ?? auth()->id(),
            'ip_address' => $this->getClientIp(),
            'user_agent' => Request::userAgent(),
            'metadata' => $data['metadata'] ?? null
        ]);
    }

    public function getTaskActivityLog(int $teamsTaskId, int $limit = 50): array
    {
        return TeamsActivityLog::where('teams_task_id', $teamsTaskId)
                              ->with('performer')
                              ->orderBy('created_at', 'desc')
                              ->limit($limit)
                              ->get()
                              ->toArray();
    }

    public function getMemberActivityLog(int $teamsMemberId, int $limit = 50): array
    {
        return TeamsActivityLog::where('teams_member_id', $teamsMemberId)
                              ->with('performer')
                              ->orderBy('created_at', 'desc')
                              ->limit($limit)
                              ->get()
                              ->toArray();
    }

    private function getClientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        return null;
    }
}