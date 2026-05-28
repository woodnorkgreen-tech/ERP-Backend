<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleInspection extends Model
{
    use SoftDeletes;

    protected $table = 'vehicle_inspections';

    protected $fillable = [
        'inspection_code', 'vehicle_id', 'inspector_id', 'logistics_officer_id',
        'inspection_type', 'inspection_date', 'inspection_time',
        'odometer_reading', 'fueling_odometer', 'amount_fueled_litres',
        'checklist', 'overall_result', 'inspector_comments',
        'condition_acceptable', 'defects_repair_immediately', 'defects_repair_few_days',
        'status',
    ];

    protected $casts = [
        'inspection_date'           => 'date',
        'checklist'                 => 'array',
        'condition_acceptable'      => 'boolean',
        'defects_repair_immediately'=> 'boolean',
        'defects_repair_few_days'   => 'boolean',
        'odometer_reading'          => 'decimal:2',
        'fueling_odometer'          => 'decimal:2',
        'amount_fueled_litres'      => 'decimal:2',
    ];

    // ── WNG checklist items (from the physical form) ──────────────────────
    public static function checklistItems(): array
    {
        return [
            ['key' => 'gauges',          'label' => 'Gauges (fuel, temperature and dashboard warning lights)'],
            ['key' => 'leaks',           'label' => 'Leaks (oil, fuel tanks and water) — check underneath'],
            ['key' => 'lighting',        'label' => 'Lighting system (headlights, brake lights, turn lights, hazards, reflectors, no plates)'],
            ['key' => 'safety_equipment','label' => 'Safety equipment (fire extinguishers, reflective triangles)'],
            ['key' => 'windscreen',      'label' => 'Windscreen — check for cracks or other damage and wiper functionality'],
            ['key' => 'ac_fans',         'label' => 'AC fans and defroster'],
            ['key' => 'brake_system',    'label' => 'Brake system (including brake) — inspect brake pads and shoes for wear'],
            ['key' => 'exhaust',         'label' => 'Exhaust system — check for any leaks or damage and ensure it is secured tightly'],
            ['key' => 'mirrors',         'label' => 'Mirrors (side mirrors and driving mirror)'],
            ['key' => 'tyres',           'label' => 'Tyres (inflation, threads, lug nuts and spare) — check for cuts, bulges or signs of wear'],
            ['key' => 'horn',            'label' => 'Horn — its functionality'],
            ['key' => 'seat_belts',      'label' => 'Seat belts'],
            ['key' => 'body',            'label' => 'Body — check for any new dents'],
            ['key' => 'wheel_spanner',   'label' => 'Wheel spanner jack'],
            ['key' => 'licenses',        'label' => 'Licences and sticker (driver, insurance and inspection)'],
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'inspector_id');
    }

    public function logisticsOfficer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'logistics_officer_id');
    }

    // ── Auto-generate inspection code ─────────────────────────────────────
    protected static function booted(): void
    {
        static::creating(function (VehicleInspection $inspection) {
            if (empty($inspection->inspection_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $inspection->inspection_code = 'INS-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
