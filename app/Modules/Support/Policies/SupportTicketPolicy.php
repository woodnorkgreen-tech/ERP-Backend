<?php

namespace App\Modules\Support\Policies;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;

class SupportTicketPolicy
{
    private function manages(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('support.manage');
    }

    public function viewAny(User $user): bool { return true; }
    public function create(User $user): bool { return (bool) $user->is_active; }
    public function view(User $user, SupportTicket $ticket): bool { return $this->manages($user) || $ticket->reporter_id === $user->id; }
    public function update(User $user, SupportTicket $ticket): bool { return $this->manages($user); }
    public function assign(User $user, SupportTicket $ticket): bool { return $this->manages($user); }
    public function reply(User $user, SupportTicket $ticket): bool { return $this->view($user, $ticket); }
    public function addInternalNote(User $user, SupportTicket $ticket): bool { return $this->manages($user); }
    public function downloadAttachment(User $user, SupportTicket $ticket): bool { return $this->view($user, $ticket); }
}
