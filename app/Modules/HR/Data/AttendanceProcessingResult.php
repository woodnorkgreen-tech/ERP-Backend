<?php

namespace App\Modules\HR\Data;

final readonly class AttendanceProcessingResult
{
    public function __construct(
        public int $recordsProcessed = 0,
        public int $unmappedPersonCount = 0,
        public int $failedPersonDayCount = 0,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            $this->recordsProcessed + $other->recordsProcessed,
            $this->unmappedPersonCount + $other->unmappedPersonCount,
            $this->failedPersonDayCount + $other->failedPersonDayCount,
        );
    }

    public function isPartial(): bool
    {
        return $this->unmappedPersonCount > 0 || $this->failedPersonDayCount > 0;
    }

    public function summary(): ?string
    {
        if (!$this->isPartial()) {
            return null;
        }

        return sprintf(
            'Partial sync: %d unmapped person(s); %d employee-day(s) failed processing.',
            $this->unmappedPersonCount,
            $this->failedPersonDayCount,
        );
    }
}
