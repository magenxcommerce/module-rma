# Right of withdrawal (EU) — RMA support

Status: **in progress** — W1–W6, W9 and W11 implemented on
`claude/withdrawal-support` (both modules); W7, W8 still planned. This document records what
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
| G1 | Guest create only finds guest orders | `Model/Resolver/GuestOrderLookupTrait.php:45` (`customer_is_guest = 1`) | A registered customer who is logged out cannot withdraw by order number + email. Deliberate for return privacy; the withdrawal form therefore does not look orders up at all (see W11). |
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

**W1 — Data patches** — done (`Setup/Patch/Data/AddWithdrawalLookups.php`)
- Reason `withdrawal` ("Withdrawal from Contract"); translated through
  `i18n/*.csv` like the other seeded labels, per-store overrides in the admin.
- Resolution `refund`.
- Status `refunded` (protected, add to `StatusCodes::PROTECTED_CODES` and
  `STATUS_EVENT_MAP` → `rma_refunded_after`). Optional: `label_sent`.

**W2 — Schema: `rma_entity`** — done
- `is_withdrawal` smallint, default 0, indexed.
- `withdrawal_declared_at` timestamp, nullable — the time the declaration
  arrived, copied from the ticket, never from the client.
- `helpdesk_ticket_code` varchar(64), nullable, indexed.
- `return_carrier` varchar(64), `return_tracking_number` varchar(128), nullable.
- Update `db_schema_whitelist.json`, `RMAInterface`, `Model/RMA.php`, the admin
  grid (filter on withdrawal) and the edit form (read-only withdrawal block).

**W3 — Eligibility fixes (benefit ordinary returns too)** — done
- E3: per item, returnable qty =
  `min(qty_shipped, qty_ordered − qty_refunded − qty_canceled) − already requested`.
  The `min` keeps a refund of never-shipped qty from also reducing shipped qty.
  A bundle shipped separately counts complete bundles from its children.
- E4: RMAs in `rejected`, `canceled_by_customer` or `refunded` no longer hold
  qty. `refunded` is excluded because that qty is already in `qty_refunded`;
  `resolved` still holds it (exchange/repair, or a refund done by hand outside
  the RMA — staff should move such RMAs to `refunded`).
- `OrderEligibility::explain(OrderInterface): string` returns one of
  `RESULT_OK`, `RESULT_DISABLED`, `RESULT_ORDER_STATUS`, `RESULT_RETURN_PERIOD`,
  `RESULT_NOT_SHIPPED`, `RESULT_NO_ITEMS`. `isOrderEligible()` is now
  `explain() === RESULT_OK`; existing error texts are unchanged.
- Behaviour change for ordinary returns: an item can no longer be requested
  beyond what was shipped, and refunded or canceled qty is no longer returnable.

**W4 — Withdrawal eligibility** — done (`Service/WithdrawalEligibility.php`)
- Deadline = end of day (UTC) of last shipment `created_at` +
  `transit_days` + `period_days`. The last shipment, because the period for an
  order delivered in several parcels starts with the last one. No shipment
  means the period has not started, so nothing is late.
- Order date and order status play no part (E1, E2).
- Never refuses: `isLate()` only flags.
- Config group **Stores > Configuration > Sales > RMA - Return Management >
  Right of Withdrawal (EU)**: `rma/withdrawal/enabled` (0),
  `period_days` (14), `transit_days` (0), `auto_approve` (1); read through
  `Helper/ModuleConfig`. `isWithdrawalEnabled()` also requires `rma/general/enabled`.

**W5 — `Service/WithdrawalService.php`** — done

```
submit(OrderInterface $order, array $items, string $ticketCode, string $declaredAt): WithdrawalResult
```

**Staff tool.** The storefront form only records the declaration (W11);
staff identify the order and items, then run this (admin action still to be
added — see *Remaining*).
`$items` is order item id => qty; empty means the whole order.

- Idempotent per ticket: a second call with the same ticket code returns the
  existing withdrawal RMA (`duplicate = true`).
- Per item, requested qty goes first to what the customer holds
  (`OrderEligibility` returnable qty minus open RMAs), then to qty not shipped
  yet; anything beyond is `qty_exceeds`.
- Held qty: one RMA via `RmaSubmitService::createRma()` (so the new-RMA emails
  fire), reason `withdrawal`, resolution `refund`, W2 fields set inside the
  same transaction through the new `prepare` callback. Status `approved` when
  `auto_approve`, `new_request` when late or auto-approve is off.
- Not shipped: when nothing of the order has shipped, the declaration covers
  every open unit and `Order::canCancel()` allows it, the order is canceled via
  `OrderManagementInterface::cancel()`. Anything else is `unshipped` for staff
  (Magento has no per-item cancel; an invoiced order needs a credit memo).
- Never throws for business conditions. `WithdrawalResult` carries
  `rma`, `orderCanceled`, `late`, `returnQty`, `unshippedQty`, `duplicate` and
  `reviewReasons` (`disabled`, `late`, `unshipped`, `cancel_failed`,
  `qty_exceeds`, `unknown_item`, `nothing_to_do`, `rma_failed`). When an RMA
  exists and there are reasons, a staff-only comment lists them on the RMA.
- Note for W7: an RMA created already `approved` fires `rma_created_after`
  and `rma_commit_after`, not `rma_approved_after` (that only fires on a
  status change). The label observer must listen to both.

**W6 — Status workflow guard (F3)** — done (`Model/RMA/WithdrawalStatusGuard.php`)
- Called from `RMARepository::save()`, so admin, REST and code paths all go
  through it.
- A withdrawal RMA can never become `rejected` (close it as `resolved` after
  review); `canceled_by_customer` only from `new_request`, `need_details` or
  `approved`; the withdrawal flag cannot be removed once set.
- Saving without a status change is always allowed.

**W7 — Return label (F4)**
- Observer on `rma_approved_after` and on `rma_commit_after` (for RMAs
  created already approved) for withdrawal RMAs.
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

**W9 — Withdrawal-aware read** — done
- `CustomerReturn` has `is_withdrawal`, `withdrawal_declared_at`,
  `helpdesk_ticket_code`, `return_carrier`, `return_tracking_number`.

**W10 — Order lookup** — dropped. The withdrawal form must not search for
orders: the declaration is a legal act as stated, whatever the consumer typed.

**W11 — Submit** — done
- The form sends free text only: email, name, order number, items (product
  names or SKUs; empty = whole order), message. The submit button is the
  legal confirmation.
- `Service/WithdrawalRequest` carries it; `Service/WithdrawalSubmitService`
  records it through `Api/WithdrawalDeclarationRecorderInterface`. When the
  order number matches an order of the store and the email matches the order
  (or the logged-in customer placed it), the record is linked to that order
  for staff — nothing else happens automatically, no RMA, no cancel.
- Recorder implemented by Magenx_Helpdesk (ticket in the Withdrawal channel +
  confirmation email); the default here reports itself unavailable, which
  turns the mutation off.
- `submitWithdrawal(input: {email, name, order_number, items, message})`
  returns `ticket_code` and `received_at`.

**Storefront** — done in `magenxcommerce/magenxcommerce`: one form, those five
fields, Turnstile action `withdrawal`; falls back to the contactUs email when
the backend lacks the mutation or the recorder.

**Remaining**
- Admin action on the withdrawal ticket (or order) to run
  `WithdrawalService::submit()` once staff have identified the order and items.
- W7 return labels (carrier adapters), W8 received qty + credit memo.

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
