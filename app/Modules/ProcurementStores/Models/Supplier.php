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
        'contact_person',
        'phone',
        'email',
        'address',
        'payment_terms',
        'status',
        'user_id',
    ];

    public function createdBy()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }
}