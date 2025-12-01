<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'event_name' => $this->event_name,
            'start_time' => $this->start_time->toIso8601String(),
            'end_time' => $this->end_time->toIso8601String(),
            'color' => $this->color,
            'is_all_day' => $this->is_all_day,
            'notes' => $this->notes ?? '',
            'is_public' => $this->is_public,
            'created_by' => (string) $this->user_id,
            'created_by_name' => $this->user->name ?? 'Unknown',
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            
            // Additional helpful data
            'duration_minutes' => $this->start_time->diffInMinutes($this->end_time),
            'is_past' => $this->end_time->isPast(),
            'is_today' => $this->start_time->isToday(),
            'is_upcoming' => $this->start_time->isFuture(),
            
            // Formatted dates for display
            'formatted_date' => $this->start_time->format('Y-m-d'),
            'formatted_start' => $this->is_all_day 
                ? $this->start_time->format('M d, Y')
                : $this->start_time->format('M d, Y g:i A'),
            'formatted_end' => $this->is_all_day 
                ? $this->end_time->format('M d, Y')
                : $this->end_time->format('M d, Y g:i A'),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function with($request)
    {
        return [
            'meta' => [
                'version' => '1.0',
            ],
        ];
    }
}