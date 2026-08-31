<?php

namespace App\Modules\Finance\CostCollector\Contracts;

use App\Modules\Finance\CostCollector\Models\CostLine;

/**
 * The single entry point for reporting a cost from anywhere in the ERP.
 *
 * Type-hint this, build a CostContext, call collect(). The implementation
 * resolves the remaining classification dimensions from the expense catalogue
 * and the project's workflow state, validates against the catalogue's own rules
 * (Job ID required / not allowed, minimum evidence, required operational
 * fields), and writes one append-only cost line.
 *
 * Nothing here moves cash. Settlement — petty cash, bank, supplier payment — is
 * a separate step against verified lines, so that a cost can be recorded before
 * anyone has decided how it gets paid.
 */
interface CollectsCost
{
    /**
     * @throws \App\Modules\Finance\CostCollector\Exceptions\CostValidationException
     *         when the context violates the catalogue rules for its expense code.
     */
    public function collect(CostContext $context): CostLine;
}
