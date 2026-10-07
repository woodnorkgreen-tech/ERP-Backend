<?php

namespace App\Modules\Finance\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A review, return, rejection or approval recorded against one version. */
class FinanceConfigApproval extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'finance_config_approvals';

    protected $guarded = ['id'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
