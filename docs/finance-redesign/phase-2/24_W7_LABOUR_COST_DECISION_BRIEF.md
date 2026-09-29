# 24 — Workflow 7 (Labour Cost) Decision Brief, for WNG Review

Prepared 2026-09-23. **W7-1 is not decided by this document.** It presents the current labour-cost
architecture (traced directly against the codebase, not assumed), the three options already on
record plus a hybrid variant, a privacy design requirement that applies regardless of which option
is chosen, and a read-only search for additional genuinely missing decisions.

**Financial-integrity stop-rule result: no defect found.** See Part 5.

---

## Part 1 — Current labour-cost architecture (bounded, read-only trace)

| Area | Finding |
|---|---|
| **Permanent-employee attendance** | `AttendanceRecord` (biometric-device-synced, `AttendanceWorkSchedule`/`AttendanceScheduleAssignment` for shifts) tracks `clock_in`/`clock_out`/`work_hours`/`overtime_hours` per employee per day. Confirmed by direct schema review: **no project, job, or enquiry field exists anywhere on this table.** |
| **Casual/technical labour** | A parallel, separate mechanism: `JobCard` (per-worker, per-day, `clock_in_time`/`clock_out_time`/`total_hours`/`overtime_hours`, `pending_approval`/`approved`/`rejected`) linked to `TechnicalLabour`, which carries its own `day_rate`. Confirmed by direct migration review: **`job_cards` also has no project/job field.** |
| **Overtime** | A real, working mechanism exists (`SyncAttendanceOvertime` command, `proposed_overtime_hours`/`approved_overtime_hours` on `AttendanceRecord`) — but it is entirely time-based, not project-based; nothing here ties an overtime hour to a project either. |
| **Payroll's existing direct-vs-overhead split** | `PayrollFinancePostingService::splitGrossByLabourClassification()` already splits total payroll gross pay company-wide using `departments.labour_classification = 'direct'` — a per-department flag distinguishing "people delivering client work" from overhead, posting to a distinct Cost-of-Sales labour account (`5200`). **This is a real, working building block** — the "direct labour vs. overhead" concept already exists institutionally — but it stops at the department level, applied as one blanket ratio per payroll run. It does not, and cannot, say which specific project that labour served. |
| **Project teams / site assignments / crew scheduling** | No "crew," "team," or project-staffing-assignment model was found anywhere in HR or Production. Projects are not currently linked to the employees working on them in any structured way. |
| **Departments** | Exist and already carry the `labour_classification` flag above; no further granularity. |
| **Wage/salary rates** | Employee salary exists on `Employee.salary`; `TechnicalLabour.day_rate` exists for casual/technical labour specifically. Both are real, usable rate sources for a standard-rate approach. |
| **Payroll journals** | Already correctly split direct/overhead at the department level (above), and already correctly post employer statutory contributions as their own line (confirmed, Phase 1 audit) — none of this is proposed to change. |
| **Project workplans** | Not traced exhaustively in this bounded review; no evidence of an existing structured "planned labour by project" concept was found during this pass. |
| **Existing Cost Collector producers** | None currently report labour. `CostContext`'s `expenseCode`/`nature`/project-identity fields are fully capable of accepting a labour cost the moment some producer computes one — no Cost Collector change is required to *receive* a labour cost once one exists; the gap is entirely upstream, in *producing* one. |

**Conclusion: labour cost is not attributed to individual projects today, in either labour
category, at any level of granularity finer than "department."** This confirms and sharpens the
existing W7-1 finding rather than changing it.

---

## Part 2 — W7-1 options

### Option A — Individual Timesheet Allocation

Employees (or their supervisor) record hours per day against a specific project.

| | |
|---|---|
| **Accuracy** | Highest — a genuine per-project, per-person actual. |
| **Operational burden** | Highest — a new daily habit for every employee whose time should be attributed, with no existing system to extend (neither `AttendanceRecord` nor `JobCard` carries a project field today; this is new capture, not a new column on an existing habit). |
| **Payroll/privacy implications** | A timesheet is inherently linkable to an individual's pay if not carefully separated — see Part 3, the privacy principle applies most directly here. |
| **Overtime handling** | Would need its own project attribution alongside regular hours — the existing overtime mechanism has no project concept to extend either. |
| **Multi-project days** | Naturally handled — a timesheet can split one day across projects. |
| **Approval requirements** | A new approval step, analogous to `JobCard`'s existing `pending_approval`/`approved` pattern but project-aware. |

### Option B — Crew-Day / Team Allocation

A site/production lead records which crew worked which project, by day, without individual
hour-level detail.

| | |
|---|---|
| **Simpler operational capture** | One entry per crew per day, not per person — a large reduction in data-entry volume versus Option A. |
| **Reduced precision** | Coarser than individual timesheets, but likely proportionate to how WNG's site/production crews actually work (see below). |
| **Suitability for production/setup crews** | Good fit if a crew genuinely works one job at a time, which is common for event/production setup work — though not verified against WNG's actual scheduling patterns in this review. |
| **Split-project days** | Requires an explicit split entry if a crew genuinely divides a day across more than one project; without one, the model implicitly assumes a crew is on one project per day. |
| **Foundation that already exists** | No "crew" model was found (Part 1) — this option requires building the crew/team concept itself, not just the allocation on top of it. |

### Option C — Controlled Periodic Allocation / Standard Cost

The Project Officer/Site Captain/Production Lead periodically records or confirms labour
allocation using approved standard rates or another controlled basis.

| | |
|---|---|
| **Lowest capture burden** | No new daily habit for site/production staff at all — one person periodically allocates. |
| **Lower precision** | Depends entirely on how carefully the allocating person estimates — most exposed to becoming a rubber-stamp over time. |
| **Reconciliation requirements** | Should periodically be checked against total payroll cost so the sum of all project allocations doesn't drift from the real total gross labour cost. |
| **Risk of subjective allocation** | Real and explicit — this is the same risk W6-1A already names for overhead allocation generally. |
| **Foundation that already exists** | `Employee.salary` and `TechnicalLabour.day_rate` already provide a rate to apply — this option could be built fastest of the three, using data that already exists. |

### Hybrid approach (practical, not a recommendation)

A plausible combination, worth WNG's explicit consideration rather than assuming one option must
apply uniformly:

- **Casual/site labour** (already using `JobCard`, already day-based, already rate-bearing via
  `TechnicalLabour.day_rate`) → captured directly, closest to Option A/B, since the daily
  clock-in/out habit already exists and would only need a project field added to it.
- **Permanent production crew** → Option B (crew-day), if WNG's crews genuinely work one project
  at a time.
- **Office/admin salaries** → remain overhead, exactly as `departments.labour_classification`
  already treats them — no change needed for this group under any option.

**No method is recommended here.** Whichever is chosen, project cost screens should say "Labour:
not included" rather than silently showing zero, until the chosen method is built and populated
with real data — this was already the recommendation on record and is not changed by this review.

---

## Part 3 — Labour privacy principle (a design requirement, regardless of option)

Project costing must never expose individual salary information to Project Operations. Confirmed
feasible without any permission change in this task: `Employee.salary` and `TechnicalLabour.day_rate`
already exist as the rate inputs a standard-rate calculation would need, and nothing prevents a
future producer from computing `hours × rate = cost` and reporting **only the resulting cost
figure** to Cost Collector — the same pattern `postFor()`/`postFromSource()` already use everywhere
else (a `CostContext` carries an amount and an attribution, never the underlying payroll detail).
Finance/HR retain the detailed underlying payroll information under their own, already-existing
permissions; Project Operations would see a project-cost line, not a payslip. This is documented
here as a design requirement for whichever W7-1 option is eventually chosen — no permission change
is made in this task.

---

## Part 4 — Search for genuinely missing W7 decisions (read-only)

Classification key: **ALREADY SUPPORTED** / **EXISTING CONTROL — PRESERVE** / **ALREADY COVERED
ELSEWHERE** / **POTENTIAL MISSING WNG DECISION** / **ACCOUNTING/PAYROLL POLICY DECISION** /
**TECHNICAL DEFECT** / **NOT APPLICABLE**.

| Topic | Classification | Basis |
|---|---|---|
| Permanent employees | **This is W7-1 itself** | Not a separate finding. |
| Casual workers | **This is W7-1 itself** | `JobCard`/`TechnicalLabour` already exist as the capture mechanism; project attribution is the same open question. |
| Overtime | **POTENTIAL MISSING WNG DECISION** | Confirmed real and working as a time-based mechanism, but with no project dimension — whichever W7-1 option is chosen must separately decide whether overtime attributes to the project that caused it or is treated as company overhead regardless. |
| Weekend/night work | **Same gap as overtime** | Not a separate finding — folds into the overtime question. |
| Allowances | **NOT VERIFIED IN THIS REVIEW** | Whether allowances (e.g. site/travel allowances) are already isolable from base pay for a per-project rate calculation was not traced. |
| Travel time | **POTENTIAL MISSING WNG DECISION** | Whether travel time counts as project labour or overhead was not addressed anywhere found. |
| Setup vs. set-down | **POTENTIAL MISSING WNG DECISION** | If WNG wants this distinction within one project's labour cost, no existing field distinguishes the two phases on either `AttendanceRecord` or `JobCard`. |
| Production/fabrication time | **This is W7-1 itself, for the casual/technical-labour population specifically** | Not a separate finding. |
| Design time | **POTENTIAL MISSING WNG DECISION** | Whether design-department time should ever attribute to a specific project (as opposed to being treated as overhead by department classification) was not addressed. |
| Project Officer time | **POTENTIAL MISSING WNG DECISION** | Same question as design time — a Project Officer's own time is not currently attributed to the specific projects they run. |
| Management time | **NOT APPLICABLE, likely** | Management time is a plausible candidate for permanent overhead treatment rather than project attribution — not decided here, but a strong candidate for "no allocation" by default. |
| Shared crew across projects | **ALREADY COVERED BY W6-3** | If a crew genuinely splits one day across two projects, that is the same "shared cost across multiple projects" question already raised under Project Costing, not a separate labour-specific one. |
| Idle time | **POTENTIAL MISSING WNG DECISION** | Whether unattributable/idle time is absorbed as overhead or spread across active projects was not addressed. |
| Rework | **POTENTIAL MISSING WNG DECISION**, split into two | See next two rows — the distinction matters enough to name separately. |
| Client-caused rework | **POTENTIAL MISSING WNG DECISION** | Whether this should still charge the project (defensible — it's real cost incurred for that job) or be absorbed as overhead was not addressed. |
| Internal-error rework | **POTENTIAL MISSING WNG DECISION** | The opposite case — WNG may not want an internal mistake's cost inflating a project's recorded cost/reducing its apparent margin; not addressed anywhere found. |
| Training time | **POTENTIAL MISSING WNG DECISION** | Plausible overhead-by-default candidate, not decided. |
| Leave/absence | **NOT APPLICABLE** | Leave/absence is, by definition, not time worked on any project — no attribution question exists here beyond ordinary payroll cost treatment, which is unaffected by W7. |
| Employer statutory costs | **EXISTING CONTROL — PRESERVE** | Already correctly posted as their own separate line (Phase 1, confirmed) — whichever W7-1 method is chosen must not disturb this. |
| Payroll benefits | **NOT VERIFIED IN THIS REVIEW** | Whether non-cash benefits should ever factor into a per-project labour rate was not traced. |
| Outsourced labour | **ALREADY COVERED — same as Subcontractors (W6, Part 3)** | Confirmed: "subcontractor"/outsourced labour already flows through the ordinary Bill/Cost Collector pipeline as an expense-code classification, not a distinct payroll-adjacent mechanism — not a W7 question. |
| Labour budget vs. actual | **DEPENDENT ON W7-1** | Cannot be built or even assessed until an actual-attribution method exists to compare against a budget. |
| Labour corrections | **ALREADY COVERED BY W6-4's pattern** | Once a labour cost is a real CostLine, correcting its project attribution should use the same transfer/reclassification mechanism W6-4 already confirms is needed generically — not a separate labour-specific correction path. |
| Late labour allocations | **ALREADY COVERED BY W6-6's pattern** | Same reasoning — a late labour cost after project closure is the same "late cost after closure" question W6-6 already confirms, not a separate one. |
| Project closure (labour-specific) | **ALREADY COVERED BY W6-5** | Labour allocation should be one of the "pending Finance items" a closure checklist can eventually check for, once W7-1 exists — not a separate closure concept. |
| Standard vs. actual rates | **This is the core of W7-1 Option C vs. A/B** | Not a separate finding. |
| Confidentiality | **This is Part 3 of this brief** | Not a separate finding. |
| Approval | **POTENTIAL MISSING WNG DECISION, method-dependent** | Whichever method is chosen needs its own approval step (e.g. `JobCard`-style `pending_approval`/`approved` for timesheets, or a periodic sign-off for Option C) — not decided until the method itself is. |
| Evidence/source record | **This is W7-1's own recommended direction** | "Extend `cost_lines` to carry a labour actual once a method is chosen" already covers this; not a separate finding. |

**No technical defect, and nothing comparable in severity to STAB-7, was found in this review.**

---

## Part 5 — Financial-integrity stop-rule check (performed, not triggered)

Specifically checked, given the task's explicit warning about labour-specific double-counting
risk, and found none:

- **Is payroll expense ever posted twice?** No — `PayrollFinancePostingService` posts the payroll
  accrual and payment through the same single balanced-entry funnel as everything else; no evidence
  of a second, parallel posting path was found.
- **Is salary payment ever duplicated?** No — C7 (already implemented) ensures exactly one
  `Payment` row per payroll run's net-pay disbursement.
- **Does payroll liability fail to clear?** Not evidenced in this review; STAB-6/C7's forward fix
  already addressed the adjacent "no `Payment` record" gap, and nothing in this review suggests a
  clearing failure.
- **Would a future project-labour-allocation mechanism risk creating a second company expense
  instead of an analytical allocation?** This is the critical risk to design against, not one found
  to already exist — because no labour-to-project attribution exists yet at all, there is currently
  nothing that could double it. **This is the central design constraint for whichever W7-1 option
  is eventually built:** a project's labour cost figure must be an allocation of the real, already-
  posted payroll expense (the same figure `splitGrossByLabourClassification()` already computes at
  the department level, taken one level further to project), never a second, independently-posted
  expense standing beside the original payroll journal. The correct architecture is the same
  "CostLine records the attribution, the original posting remains the one and only GL event"
  pattern STAB-7 already established for petty cash — Cost Collector already supports exactly this
  shape via `CostContext::$postsIndependently`, added under STAB-7, and should be reused rather than
  reinvented when W7-1 is eventually implemented.

This task therefore proceeds as ordinary Workflow 7 preparation, not as a stabilization escalation.
