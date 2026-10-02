<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: added the withdrawal and return-shipment fields.
 */
declare(strict_types=1);

namespace Magenx\Rma\Api\Data;

/**
 * @api
 */
interface RMAInterface
{
    const ENTITY_ID = 'entity_id';
    const INCREMENT_ID = 'increment_id';
    const ORDER_ID = 'order_id';
    const CUSTOMER_ID = 'customer_id';
    const STORE_ID = 'store_id';
    const CUSTOMER_EMAIL = 'customer_email';
    const CUSTOMER_NAME = 'customer_name';
    const STATUS_ID = 'status_id';
    const REASON_ID = 'reason_id';
    const RESOLUTION_TYPE_ID = 'resolution_type_id';
    const IS_WITHDRAWAL = 'is_withdrawal';
    const WITHDRAWAL_DECLARED_AT = 'withdrawal_declared_at';
    const HELPDESK_TICKET_CODE = 'helpdesk_ticket_code';
    const RETURN_CARRIER = 'return_carrier';
    const RETURN_TRACKING_NUMBER = 'return_tracking_number';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return string|null
     */
    public function getIncrementId(): ?string;

    /**
     * @param string|null $incrementId
     * @return $this
     */
    public function setIncrementId(?string $incrementId): self;

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self;

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int;

    /**
     * @param int|null $customerId
     * @return $this
     */
    public function setCustomerId(?int $customerId): self;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return string
     */
    public function getCustomerEmail(): string;

    /**
     * @param string $customerEmail
     * @return $this
     */
    public function setCustomerEmail(string $customerEmail): self;

    /**
     * @return string
     */
    public function getCustomerName(): string;

    /**
     * @param string $customerName
     * @return $this
     */
    public function setCustomerName(string $customerName): self;

    /**
     * @return int
     */
    public function getStatusId(): int;

    /**
     * @param int $statusId
     * @return $this
     */
    public function setStatusId(int $statusId): self;

    /**
     * @return int
     */
    public function getReasonId(): int;

    /**
     * @param int $reasonId
     * @return $this
     */
    public function setReasonId(int $reasonId): self;

    /**
     * @return int
     */
    public function getResolutionTypeId(): int;

    /**
     * @param int $resolutionTypeId
     * @return $this
     */
    public function setResolutionTypeId(int $resolutionTypeId): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;

    /**
     * @return bool
     */
    public function isWithdrawal(): bool;

    /**
     * @param bool $isWithdrawal
     * @return $this
     */
    public function setIsWithdrawal(bool $isWithdrawal): self;

    /**
     * @return string|null
     */
    public function getWithdrawalDeclaredAt(): ?string;

    /**
     * @param string|null $withdrawalDeclaredAt
     * @return $this
     */
    public function setWithdrawalDeclaredAt(?string $withdrawalDeclaredAt): self;

    /**
     * @return string|null
     */
    public function getHelpdeskTicketCode(): ?string;

    /**
     * @param string|null $helpdeskTicketCode
     * @return $this
     */
    public function setHelpdeskTicketCode(?string $helpdeskTicketCode): self;

    /**
     * @return string|null
     */
    public function getReturnCarrier(): ?string;

    /**
     * @param string|null $returnCarrier
     * @return $this
     */
    public function setReturnCarrier(?string $returnCarrier): self;

    /**
     * @return string|null
     */
    public function getReturnTrackingNumber(): ?string;

    /**
     * @param string|null $returnTrackingNumber
     * @return $this
     */
    public function setReturnTrackingNumber(?string $returnTrackingNumber): self;
}
