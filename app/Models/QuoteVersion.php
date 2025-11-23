<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_quote_data_id',
        'version_number',
        'label',
        'data',
        'created_by'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    public function quoteData()
    {
        return $this->belongsTo(TaskQuoteData::class, 'task_quote_data_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
