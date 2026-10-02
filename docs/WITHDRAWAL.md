# Right of withdrawal (EU) — RMA support

Status: **plan**. Nothing below is implemented yet. This document records what
blocks the module from carrying an EU consumer withdrawal today, and the work
needed in `Magenx_Rma` and `Magenx_RmaGraphQl` to close the gap.

## Background

The storefront page `/withdrawal` ("Withdraw from the contract here" →
"Confirm withdrawal") lets a consumer declare withdrawal from a purchase for one
item, several items or a whole order, within 14 days of **receiving** it.

Two separate things happen in that operation, and the module must keep them
separate:

1. **The declaration** — a legal act. It is effective once received, must be
   recorded with the date and time it arrived, and must be confirmed to the
   consumer on a durable medium (email) without undue delay. It must never be
   lost or rejected because a logistics step failed.
2. **The return** — the logistics that follow: the consumer sends the goods
   back, the merchant receives them and refunds the payment, including the
   original standard delivery cost, within 14 days of the declaration (the
   merchant may hold the refund until the goods, or proof of dispatch, arrive).

The declaration is owned by the helpdesk ticket (see the storefront repo). The
RMA owns the return. An RMA failure leaves the ticket in place for staff to
handle by hand.

## What blocks withdrawals today

References are to `main` at release 1.0.2 (`Magenx_Rma`) and the matching
`Magenx_RmaGraphQl`.

### Eligibility

| # | Blocker | Where | Effect on a withdrawal |
|---|---|---|---|
| E1 | Return period counts from the **order date** | `Service/OrderEligibility.php:100-110` (`$order->getCreatedAt()`) | The 14 days run from receipt. Setting `return_period=14` rejects every order delivered late; leaving it at 30 accepts declarations the merchant may not owe. |
| E2 | Only `complete` orders are eligible by default | `etc/config.xml:20`, checked at `Service/OrderEligibility.php:42` | An order shipped but not invoiced (authorize-then-capture) stays `processing` and is refused. |
| E3 | Item qty is `qty_ordered` | `Service/OrderEligibility.php:75` | Ignores what was actually shipped, canceled or already refunded. Unshipped qty can be "returned"; refunded qty can be returned twice. |
| E4 | Rejected and canceled RMAs still consume qty | `Service/OrderEligibility.php:119-127` (joins every RMA of the order, no status filter) | After one rejected or customer-canceled request, those items can never be requested again — including by a later, valid withdrawal. |
| E5 | Unshipped orders have no path | — | Withdrawal before dispatch needs an order/item cancel + refund, not an RMA. Nothing in the module does that. |
| E6 | Module ships disabled | `etc/config.xml:14` (`enabled=0`) | Expected default, but `isOrderEligible()` and every email return early while it is off. Deployment must enable it per website. |

### Creation path (GraphQL, `Magenx_RmaGraphQl`)

| # | Blocker | Where | Effect |
|---|---|---|---|
| G1 | Guest create only finds guest orders | `Model/Resolver/GuestOrderLookupTrait.php:45` (`customer_is_guest = 1`) | A registered customer who is logged out cannot withdraw by order number + email. Deliberate for return privacy; the withdrawal path needs a different rule (see W6). |
| G2 | Eligibility failure is one opaque message | `CreateCustomerReturn.php:85-86`, `CreateGuestReturn.php:73-74` | "This order is not eligible for a return." The withdrawal flow cannot tell "late", "not shipped" and "nothing left to return" apart, so it cannot pick cancel vs RMA vs staff review. |
| G3 | `reason_id` / `resolution_type_id` only checked for non-zero | `Service/RmaSubmitService.php:107` | An inactive reason id is accepted. Not a withdrawal blocker by itself, but the withdrawal path must set these server-side, never from client input. |
| G4 | Guests cannot comment | `Model/Resolver/AddReturnComment.php` (customer-only) | A guest who withdrew cannot send a tracking number back through the API; it has to go through the ticket by email. |

### Workflow

| # | Blocker | Where | Effect |
|---|---|---|---|
| F1 | No withdrawal marker | `etc/db_schema.xml` (`rma_entity`) | No column ties the RMA to the declaration or the helpdesk ticket. Seeded reasons (`Setup/Patch/Data/AddRmaReasons.php`) contain no `withdrawal`. |
| F2 | No `refund` resolution | `Setup/Patch/Data/AddRmaResolutionTypes.php` (`repair`, `return`, `exchange`) | `return` is ambiguous; a withdrawal always resolves as a refund. |
| F3 | Free status transitions | `Controller/Adminhtml/Rma/Save.php:86` | Any status can be set from any status. A withdrawal must not be `rejected` like an ordinary return; it can only end refunded or, for a late/invalid declaration, closed after staff review. |
| F4 | No return label, carrier or tracking | entire module (no `carrier`, `track`, `label` fields) | Staff can only attach a PDF to a comment and type tracking into text. |
| F5 | No refund step | entire module (no credit memo code) | `resolved` does nothing to the order. Refund is a manual Sales action with no link back. |
| F6 | `qty_approved` / `qty_returned` not editable in admin | only `POST /V1/rma/:rmaId/items` writes them | Staff cannot record what physically came back, so a credit memo cannot be derived from the RMA. |
| F7 | Semantic status events have no subscribers | `Model/RMARepository.php:171-213` dispatches `rma_approved_after`, `rma_received_after`, `rma_resolved_after`, … | Free extension points: label generation and refund hang here. |
| F8 | New-RMA emails only on the GraphQL path | `etc/events.xml` (`rma_commit_after`, dispatched at `Service/RmaSubmitService.php:141`) | A withdrawal RMA created via the repository or admin sends nothing. Always create through `RmaSubmitService::createRma()`. |
| F9 | Email failures are swallowed | `Service/Email/Sender.php:172-178` (log only) | Acceptable for the RMA emails, because the legal confirmation is the ticket's email, not the RMA's. Do not move the legal confirmation onto this sender. |

## Work plan

Order matters: W1–W3 are prerequisites for everything else.

### Magenx_Rma

**W1 — Data patches**
- Reason `withdrawal` ("Withdrawal from contract"), store labels for every locale
  the storefront ships.
- Resolution `refund`.
- Status `refunded` (protected, add to `StatusCodes::PROTECTED_CODES` and
  `STATUS_EVENT_MAP` → `rma_refunded_after`). Optional: `label_sent`.

**W2 — Schema: `rma_entity`**
- `is_withdrawal` smallint, default 0, indexed.
- `withdrawal_declared_at` timestamp, nullable — the time the declaration
  arrived, copied from the ticket, never from the client.
- `helpdesk_ticket_code` varchar(64), nullable, indexed.
- `return_carrier` varchar(64), `return_tracking_number` varchar(128), nullable.
- Update `db_schema_whitelist.json`, `RMAInterface`, `Model/RMA.php`, the admin
  grid (filter on withdrawal) and the edit form (read-only withdrawal block).

**W3 — Eligibility fixes (benefit ordinary returns too)**
- E3: per item, returnable qty =
  `qty_shipped − qty_refunded − already requested`.
- E4: count only RMAs whose status is not `rejected` or `canceled_by_customer`.
- Add `OrderEligibility::explain(OrderInterface): EligibilityResult` returning a
  reason code (`disabled`, `status`, `period`, `not_shipped`, `no_items`, `ok`)
  so callers can branch (fixes G2 without changing the existing error text).

**W4 — Withdrawal eligibility**
- New `Service/WithdrawalEligibility.php`, separate from `OrderEligibility`:
  - period = 14 days from the **last shipment's** `created_at` (the closest
    signal Magento has to receipt; add a config for extra transit days),
  - ignores `allowed_order_statuses` (E2): any order with shipped qty qualifies,
  - never throws for "late": returns `late=true` so the RMA is created and
    flagged for staff review.
- Config: `rma/withdrawal/enabled`, `rma/withdrawal/period_days` (14),
  `rma/withdrawal/transit_days` (0), `rma/withdrawal/auto_approve` (1).
  Follow the `magento-admin-config` skill in the storefront repo for placement.

**W5 — `Service/WithdrawalService.php`**

Single entry point, called by the GraphQL resolver (W6) after the helpdesk
ticket exists:

```
submit(order, items[], ticketCode, declaredAt): WithdrawalResult
```

- Split the requested items: shipped qty → RMA, unshipped qty → cancel.
- Shipped part: `RmaSubmitService::createRma()` with reason `withdrawal`,
  resolution `refund`, status `approved` when `rma/withdrawal/auto_approve`,
  then set the W2 columns. Going through `createRma()` keeps F8 emails.
- Unshipped part: cancel the order when nothing has shipped; otherwise do not
  partially cancel automatically (Magento has no clean per-item cancel). Flag
  it for staff in the RMA/ticket.
- Never throws for a business condition; returns what was done
  (`rma_increment_id`, `canceled`, `needs_review`, `late`).

**W6 — Status workflow guard (F3)**
- Plugin on `RMARepository::save()`: when `is_withdrawal=1`, refuse
  `rejected`; allow `canceled_by_customer` only from `new_request`/`approved`.

**W7 — Return label (F4)**
- Observer on `rma_approved_after` for withdrawal RMAs.
- `Api/ReturnLabelProviderInterface` with a `null` default implementation
  (emails return instructions via a new `rma_withdrawal_instructions` template).
- Carrier-specific provider in a separate module, chosen in config. It stores
  the label PDF through `AttachmentService` on a customer-visible comment and
  fills `return_carrier` / `return_tracking_number`.
- **Open question:** which carrier. Until decided, ship only the `null`
  provider.

**W8 — Received and refund (F5, F6)**
- Admin form: editable `qty_approved` / `qty_returned` per item.
- Observer on `rma_received_after` for withdrawal RMAs: create an offline
  credit memo for `qty_returned`, including the order's shipping amount when the
  whole order is returned (and proportionally only if store policy says so),
  then move the RMA to `refunded`. Online refunds stay a manual staff action
  (payment-method specific); the observer then only flags the RMA.
- Add an internal comment with the credit memo increment id.

### Magenx_RmaGraphQl

**W9 — Withdrawal-aware read**
- Extend `CustomerReturn` with `is_withdrawal`, `helpdesk_ticket_code`,
  `return_tracking_number`, `return_carrier`.

**W10 — Order lookup for the withdrawal form**
- The storefront needs the order's withdrawable items before submitting. Add a
  query (`withdrawalOrderItems(order_number, email)`) returning per item:
  `order_item_id`, name, sku, `qty_withdrawable`, `qty_unshipped`.
- Unlike G1, it must also find orders of registered customers who are logged
  out, **but only return item data when order number + order email match**,
  with the same generic error for every miss. Turnstile-protect it at the
  storefront proxy (it is an enumeration surface).

**W11 — Submit**
- Do not add a separate public "create withdrawal RMA" mutation. The storefront
  calls one mutation (in the helpdesk GraphQL module or a small
  `Magenx_Withdrawal` module) that creates the ticket first, then calls
  `WithdrawalService::submit()`. That keeps the declaration and the RMA in one
  server-side flow and avoids a client-ordered two-step that can half-fail.

## Admin setup after deploy

1. Stores > Configuration > RMA: enable per website; set withdrawal options.
2. Check that reason `withdrawal`, resolution `refund` and status `refunded`
   exist and have labels for every store view.
3. Email templates: review `rma_new_customer`, `rma_status_change_customer` and
   the new `rma_withdrawal_instructions` per locale.

## Testing

- Unit: `WithdrawalEligibility` (period from last shipment, late flag, partial
  shipment, refunded qty), E3/E4 eligibility fixes, `WithdrawalService` split
  (all shipped / none shipped / mixed), status guard.
- Integration (needs a Magento install): GraphQL lookup with matching and
  non-matching email, full flow approved → received → credit memo → refunded.
