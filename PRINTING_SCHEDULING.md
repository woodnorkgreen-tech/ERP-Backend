# Printing scheduling: backend plan

Issue: https://github.com/woodnorkgreen-tech/ERP-Backend/issues/27

Companion UI: https://github.com/woodnorkgreen-tech/ERP-Frontend/issues/61

## Goal

Printing staff choose a **printing date** for upcoming work. This date is independent of the project setup date: either may be earlier or later. A printer and operator can be assigned at the same time or added later. The Notion weekly print timeline supplied by the team is a workflow reference, not an integration target.

## Existing pieces

`UpcomingPrintJobController` returns Design projects with deliverables and tasks, currently filtered and ordered by project setup date. `PrintJob` already has `scheduled_at`, `operator_id`, and `machine_asset_id`; the latter two can be reused after handoff. The upcoming work has no editable printing date today.

## Implementation checklist

- Decide whether the printing date belongs to the upcoming project or to each deliverable. Use a stable source identifier and allow exactly one current schedule per chosen unit.
- Persist a nullable, date-only printing date and nullable printer and operator references for upcoming work. Save a date without requiring either assignment. Allow each field to be edited or cleared later.
- Expose the schedule on Upcoming Jobs and through a date-range query for a weekly timeline. Include unscheduled work and retain project setup date as a separate read-only planning field.
- Carry the schedule into the existing Print Job when Design hands work to Printing, without changing the project's setup date or creating duplicate work. Reconcile this with `PrintJob.scheduled_at` and existing machine/operator fields.
- Apply Printing permissions to scheduling writes and add focused tests for date independence, later assignment, rescheduling, and handoff continuity.

## First release boundary

This is day-level planning. Start/end times, machine availability, overlap prevention, confirmation states, capacity, and automatic Notion sync are outside this first release.

The schedule unit (project or deliverable) is being confirmed with the team before implementation.
