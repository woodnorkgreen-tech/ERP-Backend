<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintProjectSchedule extends Model
{
    protected $fillable = [
        'design_job_id',
        'printing_date',
        'machine_asset_id',
        'operator_id',
        'updated_by',
    ];

    protected $casts = ['printing_date' => 'date:Y-m-d'];

    public function designJob(): BelongsTo
    {
        return $this->belongsTo(DesignJob::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'machine_asset_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
