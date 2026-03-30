<?php



namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DispatchBatch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'batch_code', 'dispatch_date', 'departure_time',
        'driver_id', 'vehicle_id', 'created_by_id',
        'status', 'notes',
        'confirmed_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'dispatch_date'  => 'date',
        'confirmed_at'   => 'datetime',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_id');
    }

    public function tripRequests(): HasMany
    {
        return $this->hasMany(TripRequest::class, 'batch_id')->orderBy('stop_order');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'batch_id');
    }

    protected static function booted(): void
    {
        static::creating(function (DispatchBatch $batch) {
            if (empty($batch->batch_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $batch->batch_code = 'DSPB-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
