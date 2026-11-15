<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\RMA;

use Magenx\Rma\Model\ResourceModel\RMA\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    /**
     * @var array|null
     */
    protected ?array $loadedData = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param DataPersistorInterface $dataPersistor
     * @param RequestInterface $request
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        protected readonly DataPersistorInterface $dataPersistor,
        protected readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array|null
     */
    public function getData(): ?array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $items = $this->collection->getItems();

        foreach ($items as $rma) {
            $this->loadedData[$rma->getId()] = $rma->getData();
        }

        $persistedData = $this->dataPersistor->get('rma_entity');
        if (!empty($persistedData)) {
            $rma = $this->collection->getNewEmptyItem();
            $rma->setData($persistedData);
            $this->loadedData[(int) $rma->getId()] = $rma->getData();
            $this->dataPersistor->clear('rma_entity');
        }

        return $this->loadedData;
    }

    /**
     * @return array
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();
        $isEdit = (bool)$this->request->getParam('entity_id');

        if ($isEdit) {
            // Edit mode: hide the creation fieldset. Status, reason and resolution stay
            // editable (PRD FR-R6); Save refuses a reason/resolution change on a withdrawal.
            $meta['general']['arguments']['data']['config']['visible'] = false;
        }

        return $meta;
    }
}
