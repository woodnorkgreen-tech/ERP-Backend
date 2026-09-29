# 19 — W4 Payment Vouchers: Current-vs-Target Gap Analysis (Read-Only)

Read-only analysis comparing the WNG-confirmed W4 decisions (`03_WNG_FINANCE_DECISION_REGISTER.md`,
confirmed 2026-09-23) against the current codebase. **No code changed as a result of this
document.** An item is not called unsupported merely because its eventual UI will differ from
today's — the underlying behaviour is verified first, per instruction.

Classification key: **ALREADY SUPPORTED** / **PARTIALLY SUPPORTED** / **NOT SUPPORTED** /
**EXISTING CONTROL — PRESERVE** / **BLOCKED BY WNG DECISION** / **BLOCKED BY ROLE/PERMISSION
DECISION**.

| Requirement | WNG Requirement | Current Behaviour | Gap | Future Change Required | Dependency |
|---|---|---|---|---|---|
| W4-1 Return/Reject | Approver can Approve or Return/Reject with mandatory reason, distinct from requester's `cancel()` | No approver-initiated rejection path exists; the only route to `rejected` is `cancel()`, requester-only | **NOT SUPPORTED.** Confirmed absent by direct review of `SpendVoucherController` (`cancel()`, `approve()`, `post()` are the only status-changing actions) | Add a `returned_for_correction`/`rejected`-by-approver status and a `reject()`/`return()` action, reason required; add a `resubmit()` action if the returned-for-correction path is chosen over cancel/recreate | None technical; the editable-return-state-vs-cancel/recreate implementation choice is Phase 2B design work |
| W4-2 Value escalation | Additional senior approval above a WNG-defined high-value threshold, layered on the existing approval | One approval tier regardless of amount; identical permission for any amount | **NOT SUPPORTED.** Confirmed absent — no amount-conditional logic exists in `approve()` | Add a second approval gate, conditional on the voucher's amount exceeding a threshold, requiring a named senior-approver role/permission | **BLOCKED BY WNG DECISION** — threshold and senior approver not yet supplied |
| Generic supporting evidence | Reuse the Finance-wide attachment/evidence mechanism (W2-2/W3-4), not a `SpendVoucherAttachment` built alone | No attachment mechanism of any kind exists for vouchers — only text reference fields (`supplier_invoice_no`, `etims_invoice_no`) | **NOT SUPPORTED.** Same Finance-wide gap as W2-2/W3-4, not a new, voucher-specific one | Build the one generic mechanism once (W2-2), then wire it to vouchers — do not build a third, parallel copy | **BLOCKED BY WNG DECISION** — the shared mechanism's design is itself pending (W2-2/W3-4) |
| Duplicate voucher/payment detection | Detect probable duplicates (flag) and block high-confidence ones, with an authorized/auditable override — one Finance-wide requirement, not a separate voucher-only system | No check found on payee + payment reference + allocation across separate vouchers | **NOT SUPPORTED.** Confirmed absent; the same class of gap as the already-confirmed W2-5/W3-5 | Extend whatever matching mechanism Phase 2B builds for W2-5/W3-5 to also cover Spend Vouchers, rather than inventing a fourth copy | None technical beyond what W2-5/W3-5 already require; exact matching signals still to be designed |
| Maker/checker/poster | Preserve — three distinct permissions, each independently held | Confirmed: `FINANCE_SPEND_VOUCHERS_CREATE`/`_APPROVE`/`_POST`, each checked independently | **ALREADY SUPPORTED** | None | — |
| Self-approval guard | Preserve — approver ≠ requester unless explicitly overridden | Confirmed: `SelfApproval::allowedFor()` gate in `approve()` | **ALREADY SUPPORTED** | None | — |
| Self-posting guard | Preserve — poster ≠ requester and ≠ approver unless explicitly overridden | Confirmed present in `post()` (per Phase 1 audit and this review) | **ALREADY SUPPORTED** | None | — |
| Append-only behaviour | Preserve — no ordinary editing of a submitted voucher | Confirmed: no `update()`/edit endpoint exists on `SpendVoucherController` at all | **EXISTING CONTROL — PRESERVE.** Adding W4-1's Return for Correction must not become a backdoor to unrestricted editing — see the blueprint's explicit note that the correction mechanism (editable return state vs. cancel/recreate) is a separate, careful design choice | None yet — Phase 2B must choose the mechanism without reopening general edit access | **BLOCKED BY WNG DECISION** — indirectly, via how W4-1 is eventually built |
| Cancellation | Preserve — requester withdraws their own unposted voucher, releasing reserved allocations | Confirmed: `cancel()` correctly deletes `SpendVoucherAllocation` rows before setting `rejected`, in one transaction | **ALREADY SUPPORTED** | None | — |
| Reversal | Preserve — additive, controlled correction after posting | Confirmed: `PaymentReversalService::reverse()` refuses already-voided/reconciled payments, reverses every related journal entry, voids (never deletes) | **ALREADY SUPPORTED** | None | — |
| Partial payment behaviour | Not explicitly requested to change; verify current behaviour | `store()`'s allocation validation permits any positive amount up to a cost line's liability, across possibly-multiple vouchers over time — appears to allow partial settlement, unlike the one-Bill-per-PO restriction elsewhere | **PARTIALLY SUPPORTED / not fully verified.** The validation shape suggests support; no dedicated test was found or written in this review confirming a liability can be legitimately split across two vouchers end-to-end | Confirm with a direct test during Phase 2B before relying on this as a designed feature rather than an accidental permissiveness | None technical; verification only |
| Payment-source control | Preserve — must pick a real paying account, not a payable/liability account | Confirmed: `Rule::exists('payment_sources', ...)->where('can_make_payment', true)` | **ALREADY SUPPORTED** | None | — |
| CostLine/project attribution | Preserve — attribution rides through the underlying CostLine, not a separately-typed field | Confirmed: allocations reference `cost_line_id` directly; no separate, manually-typed project field exists on the voucher itself | **ALREADY SUPPORTED** | None | — |
| Ageing (W4-3) | *(Not decided — see register.)* | No staleness/reminder/escalation view found for a voucher sitting in `pending_approval` | N/A — no requirement confirmed yet to classify against | No action pending WNG's decision | **BLOCKED BY WNG DECISION** — W4-3 itself |
| Delegation/absence (W4-4) | *(Not decided — see register.)* | No substitute-approver/delegation mechanism found; only a standing permission-grant workaround | N/A | No action pending WNG's decision | **BLOCKED BY WNG DECISION** — W4-4 itself |

## What this means for Phase 2B

- W4-1 and W4-2 are both small, well-bounded additions to an already excellent state machine —
  neither requires touching the maker/checker/poster/append-only foundation, which should be built
  around, not replaced.
- The generic attachment mechanism (W2-2) and the generic duplicate-detection mechanism (W2-5)
  should each be built once and reused by W3-4/W3-5 and this workflow's equivalents — three
  parallel implementations of the same two ideas would be pure waste.
- Partial-payment behaviour should be confirmed by a direct test before Phase 2B design assumes it
  is a deliberate, supported feature rather than an artifact of how the allocation validation
  happens to be written today.
