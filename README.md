# Magenx_Rma

Return Merchandise Authorization (RMA): the backend engine — entities,
repositories, admin UI, REST API, emails and business events. The GraphQL
surface the headless storefront talks to lives in the sibling module
**`Magenx_RmaGraphQl`** (`magento/Magenx/RmaGraphQl`), the same split Magento
uses for `Magento_Catalog` vs `Magento_CatalogGraphQl`.

## Fork provenance

Forked from **[`mage-os/module-rma`](https://github.com/mage-os/module-rma)
tag `2.4.1`** (`6ddba4a089e370182d48bd6813c2f1c9cd007919`), MIT licensed —
see `LICENSE` (Copyright (c) Mage-OS Association) and the upstream authors in
`composer.json`. `CHANGELOG.md` is upstream's, kept as the history marker.

**Every derived file carries an attribution header** naming Mage-OS as the
copyright holder, `SPDX-License-Identifier: MIT`, and the fork note — upstream
ships no per-file headers, so these were added by us rather than inherited:

```php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
```

Files we changed behaviourally add a `Modified by MagenX:` line saying what
changed. The handful of files that are our own original work carry a Magenx
header instead, still MIT and still pointing at `LICENSE`. `.json` and `.csv`
files have no comment syntax and so carry none; `composer.json` declares
`"license": ["MIT"]` and the upstream authors directly.

What changed on the fork:

| Change | Why |
|---|---|
| `MageOS\RMA` → `Magenx\Rma`, `MageOS_RMA` → `Magenx_Rma`, observer names `mageos_rma_*` → `magenx_rma_*` | First-party ownership; matches every other module under `magento/Magenx/` |
| `etc/schema.graphqls` + `Model/Resolver/**` moved to `Magenx_RmaGraphQl` | Backend / GraphQL split |
| The whole Luma + Hyvä frontend removed (50 files) | The storefront is headless Next.js — see [Storefront](#storefront-headless) |
| `Setup/Patch/Data/*` declare their old FQCNs in `getAliases()` | Stops Magento re-running the seed patches under the new class names |
| `magento/module-customer` added to `composer.json` | Upstream omits it despite using `CustomerRepositoryInterface` |

**Deliberately unchanged**, because they are live data keys: the 12 `rma_*`
table names, the `rma/**` config section, the `rma` **admin** route front name,
and the `rma_email_*` template ids. Existing rows, config values and admin
bookmarks all survive the rename. (The *frontend* `rma` route is gone with the
Luma controllers — nothing headless used it.)

## Installation

Place the module under `app/code/Magenx/Rma` (or install
`magenx/module-rma` via Composer), then:

```bash
bin/magento module:enable Magenx_Rma
bin/magento setup:upgrade
bin/magento setup:di:compile      # production mode
bin/magento cache:flush
```

> The `setup:upgrade` command creates the database tables and inserts default data (status, reason, resolution type, item condition) via Data Patches.

### Migrating off `mage-os/module-rma`

The fork replaces the vendor package — do not run both, they declare the same
tables and the same admin routes.

1. Remove `mage-os/module-rma` from the Magento root `composer.json` **and** its
   `extra.patches` entry (`magento/patches/mage-os-module-rma.patch` no longer
   exists), then `composer update mage-os/module-rma` so `vendor/mage-os/module-rma`
   is dropped.
2. Deploy `Magenx_Rma` + `Magenx_RmaGraphQl` and run the commands above.
3. `DELETE FROM setup_module WHERE module = 'MageOS_RMA';` once the new rows exist.
4. **Re-grant ACL on custom admin roles.** The resource ids moved from
   `MageOS_RMA::*` to `Magenx_Rma::*`, so any role that is not
   `Magento_Backend::all` loses the RMA menu until it is re-saved. The default
   Administrator role is unaffected.
5. Confirm the seed tables did not gain rows (`rma_status`, `rma_reason`,
   `rma_resolution_type`, `rma_item_condition`) — the `getAliases()` guard above
   should have skipped the data patches. Even if they do re-run they are
   idempotent (`insertOnDuplicate` on a unique `code`), but they would reset an
   admin-customized label / sort order on the seeded codes.

## Configuration

Module settings are located at **Stores > Configuration > Sales > RMA - Return Management**.

### General

| Field | Type | Default | Description |
|---|---|---|---|
| Enable RMA | Yes/No | No | Enable or disable the RMA feature (scope: website) |
| Increment ID Prefix | Text | `RMA-` | Prefix for the return increment ID (e.g. `RMA-000001`) |
| Return Period (Days) | Numeric | `30` | Number of days after order placement within which a return can be requested |

### Policy

| Field | Type | Default | Description |
|---|---|---|---|
| Auto-Approve Returns | Yes/No | No | When enabled, new return requests are automatically approved |
| Allowed Order Statuses | Multiselect | `complete` | Only orders with these statuses can have a return request |

### Email Notifications

| Field | Type | Default | Description |
|---|---|---|---|
| Email Sender | Select | `General Contact` | Sender identity for RMA emails |
| New RMA Email Template (Customer) | Select | — | Email template sent to the customer when a new return is created |
| Status Change Email Template (Customer) | Select | — | Email template sent to the customer on return status change |
| New RMA Email Template (Admin) | Select | — | Email template sent to the admin when a new return is created |
| Admin Notification Email | Text (email) | — | Email address to receive admin notifications about returns |

### Attachments

| Field | Type | Default | Description |
|---|---|---|---|
| Allowed File Extensions | Text | `jpg,jpeg,png,gif,webp,mp4,mov,pdf,doc,docx,zip` | Comma-separated list of allowed file extensions |
| Maximum File Size (MB) | Numeric | `10` | Maximum allowed file size in megabytes per single file |
| Maximum Files Per Upload | Numeric | `5` | Maximum number of files allowed per RMA creation or comment |

## Admin Area

### Menu

Module entries are located under **Sales > RMA**:

- **RMA Requests** — main grid with all return requests
- **Statuses** — manage the return lifecycle statuses
- **Reasons** — manage return reasons
- **Resolution Types** — manage resolution types (refund, replacement, etc.)
- **Item Conditions** — manage item conditions (opened, sealed, etc.)

### Creating an RMA from admin

1. Go to **Sales > RMA > RMA Requests**, click **Add New RMA**
2. Search and select an order in the **Order** field (only shows orders from websites with RMA enabled)
3. Customer fields (name, email) and store are automatically filled from the order
4. Select reason and resolution type
5. In the **Items to Return** section, select the order items to include in the return, specifying quantity and condition for each
6. Save — the system automatically generates an increment ID (e.g. `RMA-000001`) and sends notification emails

### Edit RMA

In edit mode, the form shows order and item information as read-only. You can modify status, reason, resolution type and admin notes.

Changing the status automatically sends a notification email to the customer.

### Attachments

The RMA edit page displays a unified **Attachments** section above the comments timeline. This section shows all attachments associated with the RMA, regardless of whether they were uploaded at RMA creation or within a comment.

- Each attachment has a **download** link and a **delete** button
- Deleting an attachment from the unified section also removes it from the corresponding comment in the timeline (and vice versa)
- The section updates dynamically when new comments with attachments arrive via polling

### Comments / Chat

The RMA edit page includes a **Comments** section that enables communication between admin and customer.

- Admin can write comments visible to the customer or **internal notes** (not visible to the customer) via the "Visible to Customer" checkbox
- Internal notes display an **Internal Note** badge in the timeline
- Admin can attach files to comments via drag & drop or file picker
- Comments update in real time via AJAX polling with progressive backoff (10s → 30s → 60s)
- Polling pauses when the browser tab is not visible and resumes when it becomes active again
- Messages can be sent with **Ctrl+Enter** in addition to the button

## Storefront (headless)

There is **no Luma or Hyvä frontend**. The customer-facing return flows live in
the Next.js storefront and talk to `Magenx_RmaGraphQl` over GraphQL:

| Surface | Storefront route |
|---|---|
| Returns list + detail | `/account/returns` |
| Create a return | the create-return dialog on `/account/orders` |
| Guest lookup + create | `/guest/order` |

The upstream module's `Controller/Customer`, `Controller/Guest`,
`Block/{Customer,Guest,Sales}`, `etc/frontend/*` and
`view/frontend/{layout,templates,web}` trees — Luma **and** Hyvä, plus the
`hyva_config_generate_before` observer — were removed in the fork. `view/base/web/js`
stays: the admin comments template loads `rma-comments` / `rma-file-upload` /
`rma-utils` from it. `view/frontend/email` stays too — the three RMA notification
templates are declared `area="frontend"` in `etc/email_templates.xml`.

## Order Eligibility for RMA

An order is eligible for a return request if **all** of these conditions are met:

1. **RMA enabled** — the module is enabled for the order's website (`isEnabled()`)
2. **Allowed status** — the order status is among those configured in "Allowed Order Statuses"
3. **Return period** — the order date is within the period configured in "Return Period (Days)". If the period is `0`, returns are always allowed (no time limit)
4. **Available items** — the order has at least one item with remaining returnable quantity (qty ordered − qty already requested in other RMAs > 0). Virtual items, downloadable items, and parent items of configurable/bundle products are excluded

The logic is centralized in the `Service\OrderEligibility` service with the following methods:

| Method | Description |
|---|---|
| `isOrderEligible(OrderInterface $order): bool` | Checks all 4 conditions |
| `getEligibleItems(OrderInterface $order): array` | Returns items with available quantity |
| `getCustomerEligibleOrders(int $customerId, int $storeId): Collection` | Customer orders matching conditions 1-3 |

## Repositories and Service Contracts

Each entity exposes a repository with interfaces in `Api/`:

| Interface | Implementation | Methods |
|---|---|---|
| `RMARepositoryInterface` | `Model\RMARepository` | `get`, `getByIncrementId`, `save`, `delete`, `deleteById`, `getList` |
| `StatusRepositoryInterface` | `Model\StatusRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |
| `ReasonRepositoryInterface` | `Model\ReasonRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |
| `ResolutionTypeRepositoryInterface` | `Model\ResolutionTypeRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |
| `ItemConditionRepositoryInterface` | `Model\ItemConditionRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |
| `ItemRepositoryInterface` | `Model\ItemRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |
| `CommentRepositoryInterface` | `Model\CommentRepository` | `get`, `save`, `delete`, `deleteById`, `getList` |

All `getList` methods support `SearchCriteriaInterface` for filtering, sorting and pagination.

## Business Events

The module dispatches custom events in `RMARepository::save()` to allow other modules to react to return lifecycle changes.

### How dispatching works

1. **Creation**: when a new RMA is saved, `rma_created_after` fires
2. **Status change**: when the status changes, **two** events fire in sequence:
   - `rma_status_change_after` (generic, fires on any transition)
   - A specific semantic event based on the new status (e.g. `rma_approved_after`)

### Events table

| Event | When it fires | Available data |
|---|---|---|
| `rma_created_after` | New RMA created | `rma` |
| `rma_status_change_after` | Any status change | `rma`, `old_status_id`, `new_status_id` |
| `rma_approved_after` | Status &rarr; Approved | `rma`, `old_status_id`, `new_status_id` |
| `rma_rejected_after` | Status &rarr; Rejected | `rma`, `old_status_id`, `new_status_id` |
| `rma_shipped_by_customer_after` | Status &rarr; Shipped by Customer | `rma`, `old_status_id`, `new_status_id` |
| `rma_received_after` | Status &rarr; Received by Admin | `rma`, `old_status_id`, `new_status_id` |
| `rma_canceled_after` | Status &rarr; Canceled by Customer | `rma`, `old_status_id`, `new_status_id` |
| `rma_resolved_after` | Status &rarr; Resolved | `rma`, `old_status_id`, `new_status_id` |

### Example: Observer to book a carrier when a return is approved

**`etc/events.xml`** in your custom module:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="rma_approved_after">
        <observer name="my_module_book_carrier"
                  instance="MyVendor\MyModule\Observer\BookCarrierPickup"/>
    </event>
</config>
```

**`Observer/BookCarrierPickup.php`**:

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyModule\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magenx\Rma\Api\Data\RMAInterface;

class BookCarrierPickup implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        /** @var RMAInterface $rma */
        $rma = $observer->getData('rma');
        $oldStatusId = $observer->getData('old_status_id');
        $newStatusId = $observer->getData('new_status_id');

        // Your carrier booking logic here
        // $rma->getCustomerEmail(), $rma->getOrderId(), etc.
    }
}
```

### Example: Generic observer to log all status changes

```xml
<event name="rma_status_change_after">
    <observer name="my_module_log_status_change"
              instance="MyVendor\MyModule\Observer\LogStatusChange"/>
</event>
```

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyModule\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class LogStatusChange implements ObserverInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $rma = $observer->getData('rma');

        $this->logger->info('RMA status changed', [
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'old_status_id' => $observer->getData('old_status_id'),
            'new_status_id' => $observer->getData('new_status_id'),
        ]);
    }
}
```

## REST API

All endpoints require an admin integration token (`Authorization: Bearer <token>`) and are protected by ACL resources.

### RMA Entity

| Method | Endpoint | ACL | Description |
|---|---|---|---|
| `GET` | `/V1/rma/:entityId` | `Magenx_Rma::rma_manage` | Get RMA by ID |
| `GET` | `/V1/rma/increment-id/:incrementId` | `Magenx_Rma::rma_manage` | Get RMA by increment ID |
| `POST` | `/V1/rma` | `Magenx_Rma::rma_manage` | Create RMA |
| `PUT` | `/V1/rma/:entityId` | `Magenx_Rma::rma_manage` | Update RMA |
| `DELETE` | `/V1/rma/:entityId` | `Magenx_Rma::rma_manage` | Delete RMA |
| `GET` | `/V1/rma/search` | `Magenx_Rma::rma_manage` | Search RMAs (SearchCriteria) |

### RMA Items

Items are managed as a **sub-resource** of the parent RMA, following the same pattern as Magento core (e.g. `OrderInterface` / `OrderItemRepositoryInterface`). The `GET /V1/rma/:entityId` endpoint returns the RMA header only — to retrieve items, use the dedicated endpoints below.

| Method | Endpoint | ACL | Description |
|---|---|---|---|
| `GET` | `/V1/rma/:rmaId/items` | `Magenx_Rma::rma_manage` | List items for an RMA (SearchCriteria) |
| `POST` | `/V1/rma/:rmaId/items` | `Magenx_Rma::rma_manage` | Add item to an RMA |
| `DELETE` | `/V1/rma/:rmaId/items/:itemId` | `Magenx_Rma::rma_manage` | Remove item from an RMA |

### RMA Comments

Comments are managed as a **sub-resource** of the parent RMA, same as items above.

| Method | Endpoint | ACL | Description |
|---|---|---|---|
| `GET` | `/V1/rma/:rmaId/comments` | `Magenx_Rma::rma_manage` | List comments for an RMA (SearchCriteria) |
| `POST` | `/V1/rma/:rmaId/comments` | `Magenx_Rma::rma_manage` | Add comment to an RMA |

### Lookup Entities

Each lookup entity (status, reason, resolution type, item condition) exposes full CRUD endpoints.

#### Statuses

| Method | Endpoint | ACL |
|---|---|---|
| `GET` | `/V1/rma/status/:entityId` | `Magenx_Rma::rma_status` |
| `POST` | `/V1/rma/status` | `Magenx_Rma::rma_status` |
| `PUT` | `/V1/rma/status/:entityId` | `Magenx_Rma::rma_status` |
| `DELETE` | `/V1/rma/status/:entityId` | `Magenx_Rma::rma_status` |
| `GET` | `/V1/rma/status/search` | `Magenx_Rma::rma_status` |

#### Reasons

| Method | Endpoint | ACL |
|---|---|---|
| `GET` | `/V1/rma/reason/:entityId` | `Magenx_Rma::rma_reason` |
| `POST` | `/V1/rma/reason` | `Magenx_Rma::rma_reason` |
| `PUT` | `/V1/rma/reason/:entityId` | `Magenx_Rma::rma_reason` |
| `DELETE` | `/V1/rma/reason/:entityId` | `Magenx_Rma::rma_reason` |
| `GET` | `/V1/rma/reason/search` | `Magenx_Rma::rma_reason` |

#### Resolution Types

| Method | Endpoint | ACL |
|---|---|---|
| `GET` | `/V1/rma/resolution-type/:entityId` | `Magenx_Rma::rma_resolution_type` |
| `POST` | `/V1/rma/resolution-type` | `Magenx_Rma::rma_resolution_type` |
| `PUT` | `/V1/rma/resolution-type/:entityId` | `Magenx_Rma::rma_resolution_type` |
| `DELETE` | `/V1/rma/resolution-type/:entityId` | `Magenx_Rma::rma_resolution_type` |
| `GET` | `/V1/rma/resolution-type/search` | `Magenx_Rma::rma_resolution_type` |

#### Item Conditions

| Method | Endpoint | ACL |
|---|---|---|
| `GET` | `/V1/rma/item-condition/:entityId` | `Magenx_Rma::rma_item_condition` |
| `POST` | `/V1/rma/item-condition` | `Magenx_Rma::rma_item_condition` |
| `PUT` | `/V1/rma/item-condition/:entityId` | `Magenx_Rma::rma_item_condition` |
| `DELETE` | `/V1/rma/item-condition/:entityId` | `Magenx_Rma::rma_item_condition` |
| `GET` | `/V1/rma/item-condition/search` | `Magenx_Rma::rma_item_condition` |

## GraphQL API

The GraphQL surface lives in the sibling module **`Magenx_RmaGraphQl`**
(`magento/Magenx/RmaGraphQl`) — see its README for the full schema. This module
ships no `schema.graphqls` and no resolvers, so it can be deployed on a store
that never exposes returns over GraphQL.

## Status Codes

Status codes are defined as constants in `Model\RMA\StatusCodes`:

| Constant | Value |
|---|---|
| `NEW_REQUEST` | `new_request` |
| `NEED_DETAILS` | `need_details` |
| `APPROVED` | `approved` |
| `REJECTED` | `rejected` |
| `SHIPPED_BY_CUSTOMER` | `shipped_by_customer` |
| `RECEIVED_BY_ADMIN` | `received_by_admin` |
| `CANCELED_BY_CUSTOMER` | `canceled_by_customer` |
| `RESOLVED` | `resolved` |

## Tests

The unit suite runs against the module in place, so it needs the `magento/*`
packages its subjects reference:

```bash
composer install
vendor/bin/phpunit
```

`Test/Unit/bootstrap.php` stands in for the two things an installed Magento
application would already have done: it loads Composer's autoloader, and it
registers Magento's code generator so the `*Factory` classes that DI normally
compiles into `generated/code` exist for the tests that mock them. Generated
classes are written under the system temp directory, never into the repository.
