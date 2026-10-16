# Printing scheduling: backend plan

Issue: https://github.com/woodnorkgreen-tech/ERP-Backend/issues/27

Companion UI: https://github.com/woodnorkgreen-tech/ERP-Frontend/issues/61

## Existing pieces

`PrintJob` already has `scheduled_at`, `operator_id`, `machine_asset_id`, `started_at`, and `completed_at`. Printing exposes upcoming Design work and machine/operator lookups. These fields and endpoints need to be reconciled with the booking model before adding any new schema or API.

## Implementation checklist

- Define a booking linked to either upcoming Design work or a print job, with planned start/end, machine, optional operator, and provisional/confirmed state. Document whether a booking can have more than one print run.
- Add a date-range schedule read endpoint and an unscheduled-work query. Include stable source identifiers so the UI can navigate to Design work or the print job.
- Add create/update/cancel operations with permission checks and a documented same-machine overlap rule. Validate start before end and machine/operator eligibility.
- Carry a provisional booking through Print Ready handoff without a duplicate booking or print job. Keep actual execution timestamps separate from planned times.
- Cover overlapping edits and handoff continuity with feature tests.

## Decisions before implementation

- Block overlapping bookings, or allow a permitted override with a reason?
- Confirm a provisional booking automatically at Print Ready, or require Printing to confirm it?
- How should working hours, downtime, cancellations, and late handovers affect availability?

This PR starts the issue-linked branch and records the implementation scope. It does not add scheduling behavior yet.
