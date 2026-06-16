<?php

namespace App\Constants;

/**
 * ScopeClassification — single source of truth for all classification mappings.
 *
 * Every place that converts a deliverable classification to a templateId, margin,
 * or element category must import this class instead of duplicating the map.
 * Currently referenced by:
 *   - EnquiryController::updateDeliverables()
 *   - QuoteController::syncScope()
 *   - (Frontend mirrors this in src/modules/projects/utils/classificationMap.ts)
 */
class ScopeClassification
{
    // ── Canonical classification labels ──────────────────────────────────────
    const FABRICATION  = 'FABRICATION & STRUCTURES';
    const BRANDING     = 'BRANDING & SIGNAGE';
    const TECH_AV      = 'TECH/AV SOLUTIONS';
    const MERCHANDISING= 'MERCHANDISING';
    const INSTALLATION = 'INSTALLATION & SITE SERVICES';
    const LOGISTICS    = 'LOGISTICS & TRANSPORT';
    const GENERAL      = 'PRE-DEFINED';

    /**
     * Maps a classification label to a templateId used by the quote and
     * materials modules to colour-code and group elements.
     */
    public static function toTemplateId(string $classification): string
    {
        return match (strtoupper(trim($classification))) {
            self::FABRICATION   => 'fabrication',
            self::BRANDING      => 'branding',
            self::TECH_AV       => 'av',
            self::MERCHANDISING => 'props',
            self::INSTALLATION  => 'installation',
            self::LOGISTICS     => 'logistics',
            default             => 'fabrication',
        };
    }

    /**
     * Default markup margin (%) applied when a new quote element is created
     * from a scope item of this classification.
     */
    public static function defaultMargin(string $classification): int
    {
        return match (strtoupper(trim($classification))) {
            self::FABRICATION   => 30,
            self::BRANDING      => 35,
            self::TECH_AV       => 25,
            self::MERCHANDISING => 40,
            self::INSTALLATION  => 20,
            self::LOGISTICS     => 20,
            default             => 30,
        };
    }

    /**
     * Materials task element category (production | hire | outsourced).
     * Determines how the element is costed in the materials module.
     */
    public static function toElementCategory(string $classification): string
    {
        return match (strtoupper(trim($classification))) {
            self::LOGISTICS,
            self::INSTALLATION  => 'outsourced',
            self::TECH_AV       => 'hire',
            default             => 'production',
        };
    }

    /**
     * All recognised classification labels.
     */
    public static function all(): array
    {
        return [
            self::FABRICATION,
            self::BRANDING,
            self::TECH_AV,
            self::MERCHANDISING,
            self::INSTALLATION,
            self::LOGISTICS,
            self::GENERAL,
        ];
    }
}
