<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'supplier_name',
        'legal_name',
        'kra_pin',
        'vat_status',
        'etims_default',
        'residency',
        'default_vat_treatment_id',
        'wht_category_id',
        'contact_person',
        'phone',
        'email',
        'address',
        'payment_terms',
        'status',
        'user_id',
    ];

    protected $casts = [
        'etims_default' => 'boolean',
    ];

    public function createdBy()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    /**
     * The tax classification Finance assigned this supplier. Both are defaults
     * for posting, not commitments — an expense code can override the VAT
     * treatment for a particular kind of spend.
     */
    public function defaultVatTreatment()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\VatTreatment::class, 'default_vat_treatment_id');
    }

    public function whtCategory()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\WhtCategory::class, 'wht_category_id');
    }

    /**
     * Kenyan PIN format: a letter, nine digits, a letter (e.g. P051234567M).
     * Used by the validator and worth keeping next to the model so the supplier
     * form and any import agree on one definition.
     */
    public const KRA_PIN_REGEX = '/^[A-Z]\d{9}[A-Z]$/';
}