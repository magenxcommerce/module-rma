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

namespace Magenx\Rma\Model;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Model\ResourceModel\RMA as ResourceModel;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Exception\LocalizedException;

class RMA extends AbstractModel implements RMAInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'rma_entity_model';

    /**
     * @return void
     * @throws LocalizedException
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @return int|null
     */
    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id !== null ? (int)$id : null;
    }

    /**
     * @param $entityId
     * @return self
     */
    public function setEntityId($entityId): self
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * @return string|null
     */
    public function getIncrementId(): ?string
    {
        return $this->getData(self::INCREMENT_ID);
    }

    /**
     * @param string|null $incrementId
     * @return self
     */
    public function setIncrementId(?string $incrementId): self
    {
        return $this->setData(self::INCREMENT_ID, $incrementId);
    }

    /**
     * @return int
     */
    public function getOrderId(): int
    {
        return (int)$this->getData(self::ORDER_ID);
    }

    /**
     * @param int $orderId
     * @return self
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        $val = $this->getData(self::CUSTOMER_ID);
        return $val !== null ? (int)$val : null;
    }

    /**
     * @param int|null $customerId
     * @return self
     */
    public function setCustomerId(?int $customerId): self
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    /**
     * @param int $storeId
     * @return self
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    /**
     * @return string
     */
    public function getCustomerEmail(): string
    {
        return (string)$this->getData(self::CUSTOMER_EMAIL);
    }

    /**
     * @param string $customerEmail
     * @return self
     */
    public function setCustomerEmail(string $customerEmail): self
    {
        return $this->setData(self::CUSTOMER_EMAIL, $customerEmail);
    }

    /**
     * @return string
     */
    public function getCustomerName(): string
    {
        return (string)$this->getData(self::CUSTOMER_NAME);
    }

    /**
     * @param string $customerName
     * @return self
     */
    public function setCustomerName(string $customerName): self
    {
        return $this->setData(self::CUSTOMER_NAME, $customerName);
    }

    /**
     * @return int
     */
    public function getStatusId(): int
    {
        return (int)$this->getData(self::STATUS_ID);
    }

    /**
     * @param int $statusId
     * @return self
     */
    public function setStatusId(int $statusId): self
    {
        return $this->setData(self::STATUS_ID, $statusId);
    }

    /**
     * @return int
     */
    public function getReasonId(): int
    {
        return (int)$this->getData(self::REASON_ID);
    }

    /**
     * @param int $reasonId
     * @return self
     */
    public function setReasonId(int $reasonId): self
    {
        return $this->setData(self::REASON_ID, $reasonId);
    }

    /**
     * @return int
     */
    public function getResolutionTypeId(): int
    {
        return (int)$this->getData(self::RESOLUTION_TYPE_ID);
    }

    /**
     * @param int $resolutionTypeId
     * @return self
     */
    public function setResolutionTypeId(int $resolutionTypeId): self
    {
        return $this->setData(self::RESOLUTION_TYPE_ID, $resolutionTypeId);
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @param string $createdAt
     * @return self
     */
    public function setCreatedAt(string $createdAt): self
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * @param string $updatedAt
     * @return self
     */
    public function setUpdatedAt(string $updatedAt): self
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

    /**
     * @return bool
     */
    public function isWithdrawal(): bool
    {
        return (bool)$this->getData(self::IS_WITHDRAWAL);
    }

    /**
     * @param bool $isWithdrawal
     * @return self
     */
    public function setIsWithdrawal(bool $isWithdrawal): self
    {
        return $this->setData(self::IS_WITHDRAWAL, (int)$isWithdrawal);
    }

    /**
     * @return string|null
     */
    public function getWithdrawalDeclaredAt(): ?string
    {
        return $this->getData(self::WITHDRAWAL_DECLARED_AT);
    }

    /**
     * @param string|null $withdrawalDeclaredAt
     * @return self
     */
    public function setWithdrawalDeclaredAt(?string $withdrawalDeclaredAt): self
    {
        return $this->setData(self::WITHDRAWAL_DECLARED_AT, $withdrawalDeclaredAt);
    }

    /**
     * @return string|null
     */
    public function getHelpdeskTicketCode(): ?string
    {
        return $this->getData(self::HELPDESK_TICKET_CODE);
    }

    /**
     * @param string|null $helpdeskTicketCode
     * @return self
     */
    public function setHelpdeskTicketCode(?string $helpdeskTicketCode): self
    {
        return $this->setData(self::HELPDESK_TICKET_CODE, $helpdeskTicketCode);
    }

    /**
     * @return string|null
     */
    public function getReturnCarrier(): ?string
    {
        return $this->getData(self::RETURN_CARRIER);
    }

    /**
     * @param string|null $returnCarrier
     * @return self
     */
    public function setReturnCarrier(?string $returnCarrier): self
    {
        return $this->setData(self::RETURN_CARRIER, $returnCarrier);
    }

    /**
     * @return string|null
     */
    public function getReturnTrackingNumber(): ?string
    {
        return $this->getData(self::RETURN_TRACKING_NUMBER);
    }

    /**
     * @param string|null $returnTrackingNumber
     * @return self
     */
    public function setReturnTrackingNumber(?string $returnTrackingNumber): self
    {
        return $this->setData(self::RETURN_TRACKING_NUMBER, $returnTrackingNumber);
    }
}
