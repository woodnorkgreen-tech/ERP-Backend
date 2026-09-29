# 26 — Workflow 8 (Logistics / Fleet Cost) Decision Brief, for WNG Review

Prepared 2026-09-23. **None of W8-1 through W8-5 is decided by this document.** It presents a
bounded, read-only trace of the actual Logistics module against Finance/Cost Collector, the
options already on record in `03_WNG_FINANCE_DECISION_REGISTER.md`, and a read-only search for
additional genuinely missing decisions.

**Financial-integrity stop-rule result: no defect found.** See Part 5. Nothing in Logistics posts
to Finance at all today, automatically or otherwise — the risk this rule guards against (a cost
posted twice) cannot occur where no automatic posting exists yet.

---

## Part 1 — Current Logistics/Fleet architecture (bounded, read-only trace)

| Area | Finding |
|---|---|
| **Vehicles** | `Vehicle` (plate, make/model, type, capacity, fuel type, `odometer_km`, GPS status/location, insurance expiry, assigned driver) — a real, working fleet registry. No cost/depreciation field of any kind. |
| **Drivers** | `Driver` links directly to `Employee` — driver labour cost is the same question as Workflow 7 (labour), not a separate logistics-cost question. |
| **Trip requests** | `TripRequest` **already carries `project_id`**, plus context type, pickup/destination (with lat/lng), required date, priority, full approval fields (`approved_by_id`/`approved_at`/`rejection_reason`), and assignment fields (driver, vehicle, assigned by/at, start/complete timestamps). **This is the strongest existing foundation found in this review** — the operational project linkage for trip-based costing already exists; only a cost figure and a Cost Collector producer are missing. |
| **Deliveries / multi-stop dispatch** | `DispatchBatch` → `Delivery` (`total_km`, `total_duration_minutes`, driver, vehicle) → `DeliveryStop` (`distance_from_prev_km` per leg, linked to its own `trip_request_id`, and therefore transitively to its own `project_id`). **Confirmed: the exact data a distance-based multi-project split would need already exists** — a five-stop delivery batch serving three different projects already has each leg's distance and each leg's project on record. |
| **Vehicle maintenance** | `VehicleMaintenanceLog` (`maintenance_type`, `activity_type`, `cause_of_failure`, `odometer_reading`, `service_provider`, `cost_breakdown` (JSON), `total_cost`, `downtime_days`, its own approval/confirmation fields, before/after photos). A genuinely complete operational + cost record — with **no project field**, and confirmed by direct review of `MaintenanceController::approve()` that approval triggers no Bill or CostLine creation of any kind. |
| **Vehicle inspection** | `VehicleInspection` exists (compliance/condition checking) — operational only, no cost dimension relevant to project costing. |
| **Fuel** | "Fuel" appears only as a vehicle attribute (`fuel_type`) and in inspection/maintenance form fields — **no dedicated fuel-purchase cost record was found anywhere.** If WNG buys fuel via card/account, that cost is presumably already a supplier Bill somewhere in Finance, but nothing in Logistics itself tracks fuel spend as its own figure. |
| **External transport (hired trucks, couriers, outsourced delivery)** | No distinct model or mechanism was found for this in Logistics. Structurally, any external supplier — a haulier, a courier company — can already be paid through the existing Bill or Direct Bill mechanism like any other supplier, using an appropriate expense-code classification (the same pattern already confirmed for subcontractors under Workflow 6). **The likely real gap is project-coding consistency, not a missing payment mechanism.** |
| **Cost Collector integration** | Confirmed by direct search: **zero** references to `CostCollectorService`, `CostContext`, `postFromSource`, or `CollectsCost` exist anywhere in `app/Modules/Logistics`. Nothing here reaches Cost Collector automatically, in either direction. |

**Conclusion: Logistics has richer operational data than Phase 1's original finding suggested —
trip-to-project linkage and per-leg distance already exist — but genuinely zero automatic
connection to Finance exists anywhere.** The gap is real, and it is specifically the *link*, not
the underlying operational data capture, which is largely already there for trips/deliveries (less
so for fuel, tyres, insurance, and depreciation, which don't appear to be tracked at all yet).

---

## Part 2 — W8-1: how should logistics cost reach a project?

| | |
|---|---|
| **Option A — Trip-based allocation** | Each trip links project, vehicle, driver, date, distance, and direct trip expenses; project logistics cost derives from the trip. **Advantage:** the core linkage (`TripRequest.project_id`) and per-leg distance (`DeliveryStop.distance_from_prev_km`) already exist — this is substantially a "connect what's already captured" build, not a new capture system. **Disadvantage:** a cost-per-kilometre or per-trip rate still needs defining (W8-3), and fuel/tyre/insurance/depreciation inputs are not confirmed to exist as trackable figures yet. |
| **Option B — Direct expense coding only** | Continue attributing logistics cost only when a Bill/petty-cash/payment item happens to be manually coded to a project. **Advantage:** zero new build. **Disadvantage:** misses all internal fleet usage entirely (fuel/maintenance/depreciation on WNG's own vehicles never reaches any project), which is exactly the incomplete-profitability risk already named in the original Phase 1 finding and in W6-1's "Direct Margin currently omits logistics" caveat. |
| **Option C — Hybrid** | Trip/project attribution for internal fleet usage; direct CostLine coding for external hired transport/courier (already flows through the existing Bill/Direct Bill mechanism, per Part 1); shared-trip allocation via W6-3 for multi-project trips. **Advantage:** matches what already exists most closely — external transport needs no new accounting path at all, only consistent coding; internal fleet usage is the only genuinely new work, and it has a head start in the existing `TripRequest`/`Delivery` data. **Disadvantage:** still two different mental models for staff to learn (trip-based for internal, direct-coding for external), though this mirrors the already-accepted PO-backed-vs-Direct-Bill split elsewhere in Finance. |

**Recommended technical direction (separate from the WNG decision, not a substitute for it):**
Option C, because it is the only one that doesn't discard the real, already-captured `TripRequest`/
`DeliveryStop` linkage, and because it doesn't invent a new payment mechanism for external
transport where a sound one (Bill/Direct Bill) already exists.

## Part 3 — W8-3: internal vehicle cost method

| | |
|---|---|
| **Option A — Actual direct trip costs only** | Only project-specific fuel/tolls/etc. actually incurred on that trip. Lowest data requirement, most defensible, most incomplete (misses maintenance, depreciation, insurance entirely). |
| **Option B — Standard cost per kilometre/trip** | A Finance-approved internal rate applied to `Vehicle.odometer_km`/`Delivery.total_km`. Buildable now with existing data; accuracy depends entirely on how the rate is set and maintained. |
| **Option C — Fully loaded vehicle cost** | Fuel + maintenance + tyres + insurance + depreciation, allocated. Most complete, but confirmed to require data WNG may not currently track at all (fuel-purchase-as-its-own-figure, tyre cost, insurance cost, depreciation) — building this prematurely risks inventing numbers rather than using real ones. |

**Data honestly assessed:** kilometres and maintenance total cost already exist as real, captured
figures. Fuel, tyres, insurance, and depreciation as their own trackable inputs were not found
anywhere in this review — Option C cannot be built reliably until/unless those inputs are confirmed
to exist or are newly captured.

## Part 4 — W8-4 (shared trips) and W8-5 (maintenance treatment)

**W8-4 — allocation basis for a trip/batch serving multiple projects.** The data most readily
available is distance per leg (`DeliveryStop.distance_from_prev_km`), making a kilometre-based
split the most directly buildable option without new data capture — but per-stop count (equal
weight regardless of distance) or a load/usage basis are also legitimate choices WNG may prefer for
different reasons (e.g. simplicity, or weighting by cargo volume rather than distance). Whatever
basis is chosen must satisfy W6-3's invariant: the sum of project allocations equals the
attributable trip cost, never inflated.

**W8-5 — vehicle maintenance treatment.** `VehicleMaintenanceLog.maintenance_type`/
`activity_type`/`cause_of_failure` already give enough structure to distinguish, at minimum:
routine service, tyres, and normal wear (candidates for fleet overhead or the internal vehicle
rate) versus project-caused damage, an accident/incident during a project, or an emergency repair
mid-project (candidates for direct project charge) versus statutory/licensing cost (overhead).
None of these categories is assigned a treatment here — that is WNG's decision.

## Part 5 — Financial-integrity stop-rule check (performed, not triggered)

- **Is one fuel/transport cost currently posted twice?** No evidence found — no automatic Logistics
  posting of any kind exists, so there is nothing to duplicate.
- **Do Logistics and Finance independently post the same supplier expense?** No — Logistics posts
  nothing to Finance at all today; whatever supplier expense exists (e.g. a fuel card bill) would
  only ever be posted once, by Finance's own existing Bill/AP mechanism, entirely independent of
  Logistics' operational records.
- **Would a future project transport CostLine risk reposting an already-posted Bill/Payment
  expense?** This is the design risk to guard against when W8-1 is eventually built, not something
  found to already exist. The correct architecture, once built, is the same principle established
  under STAB-7 and recommended for Workflow 7: an internal-fleet-usage CostLine should be a new
  analytical allocation reflecting real (or standard-rate) cost, while an external-transport cost
  that already exists as a Bill/Direct Bill must not also get a second, independent Logistics-side
  posting — the trip record should reference the existing Bill's CostLine, not create a competing
  one.
- **Is vehicle maintenance systematically duplicated?** No — it is not posted to Finance at all
  today, so it cannot yet be duplicated; this is the same "not built" finding as fuel, not a defect.

This task therefore proceeds as ordinary Workflow 8 preparation, not as a stabilization escalation.

---

## Part 6 — Search for genuinely missing W8 decisions (read-only)

Classification key: **ALREADY SUPPORTED** / **EXISTING CONTROL — PRESERVE** / **ALREADY COVERED
ELSEWHERE** / **POTENTIAL MISSING WNG DECISION** / **ACCOUNTING/POLICY DECISION** / **TECHNICAL
DEFECT** / **NOT APPLICABLE**.

| Topic | Classification | Basis |
|---|---|---|
| Trip approval | **ALREADY SUPPORTED** | `TripRequest` already has a full approve/reject/assign workflow with reasons and timestamps. |
| Trip/project linkage | **ALREADY SUPPORTED (operationally)** | `TripRequest.project_id` already exists — this is the strongest finding of this review. |
| Multiple projects per trip | **ALREADY COVERED BY W6-3/W8-4** | The data (`DeliveryStop`) already supports it; the allocation basis is W8-4's open question. |
| Internal vehicle rate | **This is W8-3 itself** | Not a separate finding. |
| Fuel attribution | **NOT SUPPORTED** | No fuel-purchase cost record found anywhere in Logistics; if it exists at all today, it's purely inside Finance's own AP records with no Logistics-side linkage. |
| Maintenance allocation | **This is W8-5 itself** | Not a separate finding. |
| Tyre cost | **NOT SUPPORTED** | No distinct tyre-cost tracking found separate from general maintenance cost breakdown (`cost_breakdown` JSON may contain it, but it's not a first-class field). |
| Depreciation | **NOT SUPPORTED** | No depreciation concept exists anywhere in Logistics or, per Workflow 6/9's findings, in the Asset Register either. |
| Driver labour | **ALREADY COVERED BY WORKFLOW 7** | `Driver.employee_id` ties directly to the same labour-cost question already raised there — not a separate Logistics question. |
| Driver allowances | **ALREADY COVERED BY WORKFLOW 7** | Same reasoning — an allowance is a payroll/HR question, not a Logistics one. |
| Hired vehicles | **ALREADY COVERED BY W8-1 Option C** | Not a separate finding — this is exactly the "external hired transport" half of the hybrid option. |
| Courier | **ALREADY COVERED BY W8-1 Option C** | Same reasoning. |
| Tolls/parking | **ALREADY COVERED BY W8-1** | These would be direct trip expenses under Option A/C — not a separate decision. |
| Vehicle damage | **ALREADY COVERED BY W8-5** | Folded into the maintenance-treatment question. |
| Traffic fines/penalties | **POTENTIAL MISSING WNG DECISION** | Not addressed by any existing question — whether a fine incurred during a project trip is a project cost, a driver's personal liability, or company overhead was not found decided anywhere. |
| Accidents/incidents | **ALREADY COVERED BY W8-5** | Folded into the maintenance-treatment question (accident/incident cost is explicitly one of the categories listed there). |
| Personal/non-project vehicle use | **POTENTIAL MISSING WNG DECISION** | If any company vehicle is ever used for non-project, non-overhead (i.e. personal) purposes, no policy or tracking for this was found — worth confirming whether this is a real scenario at all before treating it as a gap. |
| Empty return trip | **POTENTIAL MISSING WNG DECISION** | Whether the cost of a vehicle's empty return leg after a delivery attributes to the same project as the outbound leg, or is treated as overhead, was not addressed. |
| Workshop-to-site travel | **ALREADY COVERED BY WORKFLOW 7 (W7-5)** | This is the travel-time question already raised there, for the labour/time side; the vehicle-cost side of the same trip is W8-1/W8-3. |
| Setup/set-down | **ALREADY COVERED BY WORKFLOW 7 (W7-6)** | The work-phase classification already covers this for labour; for logistics it would be the same trip under W8-1, not a separate question. |
| Client-caused additional trip | **ALREADY COVERED BY W8-1/W6-3 pattern** | An extra trip caused by a client change is still just a trip under W8-1 — attributable to the project like any other, with the same "client-caused" analytical tag concept already established for labour (W7-8) potentially reusable here. |
| Internal-error/rework trip | **ALREADY COVERED BY the W7-9 pattern** | Same reasoning as above, mirroring the internal-rework classification already confirmed for labour. |
| Vehicle downtime | **NOT APPLICABLE to project costing directly** | `VehicleMaintenanceLog.downtime_days` already exists as an operational metric; it is not itself a cost figure, though it may explain why a project experienced a logistics delay — a scheduling/operations concern more than a costing one. |
| Cost correction | **ALREADY COVERED BY W6-4's pattern** | Once a logistics CostLine exists, correcting its project attribution should use the same mechanism, not a separate one. |
| Late logistics cost | **ALREADY COVERED BY W6-6's pattern** | Same reasoning as labour's late-allocation question. |
| Project closure | **ALREADY COVERED BY W6-5** | Outstanding logistics costs should be one of the closure checklist's eventual open-item categories, once W8-1 exists. |
| Fleet budget vs. actual | **NOT VERIFIED IN THIS REVIEW** | Whether Logistics maintains its own budget concept for fleet spend (separate from project budgets) was not traced. |
| Evidence | **PARTIALLY SUPPORTED** | `VehicleMaintenanceLog` already carries before/after photos; trip-level evidence (e.g. a fuel receipt) was not confirmed to exist as a captured field. |
| Approval | **ALREADY SUPPORTED** | Both `TripRequest` and `VehicleMaintenanceLog` already have real approval workflows. |
| Cost Collector integration | **This is W8-1 itself** | Not a separate finding — it is the central question of this whole workflow. |

**No technical defect, and nothing comparable in severity to STAB-7, was found in this review.**
