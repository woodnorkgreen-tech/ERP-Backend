<?php

return [
    /**
     * Categories that are eligible for individual board/sheet tracking
     * These materials will create trackable board records
     */
    'tracking_categories' => ['Boards', 'Sheet Materials', 'Veneer'],

    /**
     * Default dimensions in mm for board ingestion
     * Used when material attributes don't specify dimensions
     */
    'default_dimensions' => [
        'length' => 2440,
        'width' => 1220,
        'thickness' => 18,
    ],

    /**
     * Variance tolerance multiplier for consumption calculations
     * A value of 1.05 means 5% over expected is allowed before raising exception
     */
    'variance_tolerance_multiplier' => 1.05,

    /**
     * Allowed board status values
     */
    'statuses' => [
        'Quarantine',   // Initial receiving state for QA
        'Available',    // Ready for allocation
        'Allocated',    // Allocated to a job
        'At Station',   // At processing station
        'WIP',          // Work in progress
        'Consumed',     // Finished processing
        'Scrapped',     // Discarded/waste
    ],

    /**
     * Valid status transitions
     * Maps from current status to array of allowed next statuses
     */
    'valid_transitions' => [
        'Quarantine' => ['Available', 'Scrapped'],
        'Available'  => ['Allocated', 'Scrapped'],
        // Quarantine added as return destination — Grade C/D boards go back for supervisor review
        'Allocated'  => ['At Station', 'Available', 'Quarantine', 'Scrapped'],
        'At Station' => ['WIP', 'Available', 'Quarantine', 'Scrapped'],
        'WIP'        => ['Consumed', 'Available', 'Quarantine', 'Scrapped'],
        'Consumed'   => [],  // Terminal state
        'Scrapped'   => [],  // Terminal state
    ],
];
