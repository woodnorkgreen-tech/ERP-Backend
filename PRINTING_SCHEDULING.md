# Printing scheduling: backend

Issue: https://github.com/woodnorkgreen-tech/ERP-Backend/issues/27

Companion UI: https://github.com/woodnorkgreen-tech/ERP-Frontend/issues/61

## Goal

Printing staff choose a **printing date** for upcoming work. This date is independent of the project setup date: either may be earlier or later. A printer and operator can be assigned at the same time or added later. The Notion weekly print timeline supplied by the team is a workflow reference, not an integration target.

## Existing pieces

`UpcomingPrintJobController` returns Design projects with deliverables and tasks, currently filtered and ordered by project setup date. `PrintJob` already has `scheduled_at`, `operator_id`, and `machine_asset_id`; the latter two can be reused after handoff. The upcoming work has no editable printing date today.

## Implemented flow

- `print_project_schedules` stores one row per Design project (`design_job_id`) with a nullable date, printer, and operator.
- `PATCH /api/printing/upcoming-jobs/{designJob}/schedule` saves or clears individual fields. It requires `printing.jobs.update`; changing the operator also requires the existing Printing lead assignment permission.
- `GET /api/printing/upcoming-jobs` returns printing date and assignments beside the separate project setup date. It supports `printing_schedule=scheduled|unscheduled`.
- `GET /api/printing/upcoming-jobs/timeline?from=YYYY-MM-DD&to=YYYY-MM-DD` returns scheduled projects for a date range of up to 31 days. The timeline filters by printing date, independent of project setup date.
- `GET /api/printing/upcoming-jobs/report?from=YYYY-MM-DD&to=YYYY-MM-DD` returns scheduled projects in the selected range plus unscheduled projects whose setup date is in the range. `unscheduled_scope=upcoming` includes all upcoming unscheduled projects instead. The report uses one row per project for totals and assignment breakdowns.
- Accepted Design handoffs copy the project's schedule into each new print job. Re-accepting a handoff only fills empty schedule fields on an existing job, so job-specific edits are preserved.

## First release boundary

This is day-level planning. Start/end times, machine availability, overlap prevention, confirmation states, capacity, and automatic Notion sync are outside this first release.

The team confirmed one printing date per project. Every deliverable under that project shares the date.
