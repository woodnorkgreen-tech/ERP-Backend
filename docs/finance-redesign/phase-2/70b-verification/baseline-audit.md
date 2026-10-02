# Report 70B capability audit before implementation
Backend master d7e8945c047362689eaae19419581a3a640b679a; clean, no modified/untracked files.
Frontend master 2ae5114a3964c54abb9de5eb4fecf7b52aec9d7a; clean, no modified/untracked files.
The attachment's dirty-state description predates the authorized 70A commit/push. No 70B commit/push is authorized.

Actual code inspected: LibraryMaterial, registration/controller/validation/control helpers, MaterialFormModal, QuickCreateMaterialFields, ConsumableUnit/Movement/Count, ConsumableUnitService/Controller, ConsumableUnits.vue, OperationsDesk, Dashboard, InventoryService/Valuation, StoresValuationReadinessService, StockMovementPoster/ReversalService, StoresCostProducer, StockCountController and Finance StockMovementPostingService; Report 70A read.

| Capability | Backend | Frontend | Connection before 70B |
|---|---|---|---|
| 1 Create material | Implemented | Implemented | Fully connected |
| 2 Select tracking | Implemented | Partial: specific selection indirect | Partial |
| 3 Save CONSUMABLE_UNIT | Implemented canonical projection of persisted tracking_mode | Implemented | Fully connected |
| 4 Edit material | Implemented | Implemented; control toggles can reset choice | Partial |
| 5 Quick create | Implemented | Partial tracking choices | Partial |
| 6 Receive | Implemented | Implemented textarea, lacks decimal totals | Partial |
| 7 Generate units | Implemented | Missing result | Partial |
| 8 View units | Implemented | Implemented sparse register | Partial |
| 9 Select issue unit | Implemented | Implemented | Fully connected |
| 10 Multi-unit issue | Implemented | Implemented | Fully connected |
| 11 Return | Implemented | Implemented lacks return limits | Partial |
| 12 Offcut | Implemented | Implemented lacks generated-child result | Partial |
| 13 Waste | Implemented | Implemented notes; lacks reference field | Partial |
| 14 Physical count | Implemented immutable evidence | Implemented | Fully connected |
| 15 Count variance | Implemented | Implemented | Fully connected |
| 16 Count adjustment | Missing | Missing | Missing |
| 17 Valuation | Implemented | Implemented | Fully connected |
| 18 Valuation repair | Missing | Missing | Missing |
| 19 Unit history | Implemented | Implemented | Fully connected |
| 20 Reconciliation | Implemented | Implemented | Fully connected |
| 21 Project costing | Implemented | Project/requirement selection implemented | Fully connected |
| 22 Finance valuation/reconciliation | Existing services integrate unit summary | Existing Finance inventory/readiness | Fully connected |

Additional gaps: composed operational identity/search, general master tracking-change safety, review lifecycle and explicit repair eligibility. Preserve existing database CU sequence, atomic stock posting and specific-identification costing. Reuse stores.review and stores.adjust_quantity, enforce a different approving reviewer as existing StockCountController does. Accounting uses existing inventory/adjustment mapping; missing mapping remains an explicit policy gate rather than an invented account.
