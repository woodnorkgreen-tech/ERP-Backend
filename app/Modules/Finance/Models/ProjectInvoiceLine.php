<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line on a client invoice.
 *
 * The reason lines exist is that an invoice header cannot say what Value Added
 * Tax is due. Tax is a property of what was sold — a stand build is standard
 * rated, a disbursement recharged at cost may not be — so a single typed figure
 * on a header is an assertion nobody can check. A priced line can be checked,
 * and can post.
 */
class ProjectInvoiceLine extends Model
{
    protected $table = 'project_invoice_lines';

    protected $fillable = [
        'project_invoice_id', 'description', 'quantity', 'unit_price',
        'vat_treatment_id', 'revenue_account_id',
        'net_amount', 'tax_amount', 'total_amount', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ProjectInvoice::class, 'project_invoice_id');
    }

    public function vatTreatment(): BelongsTo
    {
        return $this->belongsTo(VatTreatment::class, 'vat_treatment_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }
}
