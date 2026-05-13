<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverablesBlueprint extends Model
{
    protected $fillable = [
        'name',
        'category',
        'description',
        'materials',
        'labour',
        'keywords',
    ];

    protected $casts = [
        'materials' => 'array',
        'labour' => 'array',
        'keywords' => 'array',
    ];
}
