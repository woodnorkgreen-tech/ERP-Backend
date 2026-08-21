<?php

namespace App\Modules\Finance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One leg of an entry, with the account it hit named rather than numbered. */
class JournalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_type' => $this->entry_type,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'fx_rate' => (string) $this->fx_rate,
            'base_amount' => (string) $this->base_amount,
            'description' => $this->description,

            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
                'category' => $this->account->category,
                'normal_balance' => $this->account->normal_balance,
            ]),

            // The analysis dimensions the posting carried. Without these a
            // ledger line cannot be traced back to the project it belongs to.
            'cost_centre_id' => $this->cost_centre_id,
            'activity_id' => $this->activity_id,
            'project_id' => $this->project_id,
            'project_enquiry_id' => $this->project_enquiry_id,
        ];
    }
}
