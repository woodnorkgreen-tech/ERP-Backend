<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\RequisitionVerificationService;

/** Establish the verified-request premise for downstream accounting/control tests. */
trait VerifiedFinancialRequisitionFixture
{
    protected function verifiedRequisitionFixture(PettyCashRequisition $r): PettyCashRequisition
    {
        $status = $r->status;
        $verifier = User::factory()->create(['is_active' => true]);
        $verifier->givePermissionTo(RequisitionVerificationService::PERMISSION);
        $r->forceFill(['responsible_verifier_id' => $verifier->id, 'status' => 'pending'])->save();
        if (! $r->items()->exists()) {
            $r->items()->create(['description' => $r->purpose, 'amount' => $r->total_amount,
                'payee_name' => $r->payee_name ?: 'Test receiver']);
        }
        $service = app(RequisitionVerificationService::class);
        $service->submit($r, $r->user_id);
        $service->review($r->id, $verifier, 'verified', 'Verified accounting test fixture.');
        $r->refresh()->forceFill(['status' => $status])->save();

        return $r->refresh();
    }
}
