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

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Model\AbstractRepository;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Concrete subclass that exposes the protected methods under test as public.
 */
class ConcreteTestRepository extends AbstractRepository
{
    public function __construct(
        AbstractDb $resourceModel,
        CollectionProcessorInterface $collectionProcessor,
        private readonly AbstractModel $entity,
        private readonly AbstractCollection $collection,
        private readonly SearchResultsInterface $searchResults,
        private readonly string $label = 'widget'
    ) {
        parent::__construct($resourceModel, $collectionProcessor);
    }

    protected function getEntityLabel(): string
    {
        return $this->label;
    }

    protected function createEntity(): AbstractModel
    {
        return $this->entity;
    }

    protected function createCollection(): AbstractCollection
    {
        return $this->collection;
    }

    protected function createSearchResults(): SearchResultsInterface
    {
        return $this->searchResults;
    }

    public function load(int $entityId): AbstractModel
    {
        return $this->loadEntity($entityId);
    }

    public function save(AbstractModel $entity): AbstractModel
    {
        return $this->saveEntity($entity);
    }

    public function delete(AbstractModel $entity): bool
    {
        return $this->deleteEntity($entity);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        return $this->performGetList($searchCriteria);
    }
}

// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses -- test-local fixture; keeping it beside the test it serves.
class AbstractRepositoryTest extends TestCase
{
    private AbstractDb&MockObject $resourceModel;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private AbstractModel&MockObject $entity;
    private AbstractCollection&MockObject $collection;
    private SearchResultsInterface&MockObject $searchResults;
    private ConcreteTestRepository $repository;

    protected function setUp(): void
    {
        $this->resourceModel      = $this->createMock(AbstractDb::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->entity             = $this->createMock(AbstractModel::class);
        $this->collection         = $this->createMock(AbstractCollection::class);
        $this->searchResults      = $this->createMock(SearchResultsInterface::class);

        $this->repository = new ConcreteTestRepository(
            $this->resourceModel,
            $this->collectionProcessor,
            $this->entity,
            $this->collection,
            $this->searchResults
        );
    }

    // -------------------------------------------------------------------------
    // loadEntity
    // -------------------------------------------------------------------------

    public function testLoadEntityReturnsEntityWhenFound(): void
    {
        $this->entity->method('getEntityId')->willReturn(5);

        $result = $this->repository->load(5);

        $this->assertSame($this->entity, $result);
    }

    public function testLoadEntityCallsResourceModelWithId(): void
    {
        $this->entity->method('getEntityId')->willReturn(5);

        $this->resourceModel->expects($this->once())
            ->method('load')
            ->with($this->entity, 5);

        $this->repository->load(5);
    }

    public function testLoadEntityThrowsNoSuchEntityExceptionWhenNotFound(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->entity->method('getEntityId')->willReturn(null);

        $this->repository->load(99);
    }

    public function testLoadEntityExceptionMessageIncludesEntityLabelAndId(): void
    {
        $this->entity->method('getEntityId')->willReturn(null);

        try {
            $this->repository->load(42);
            $this->fail('Expected NoSuchEntityException');
        } catch (NoSuchEntityException $e) {
            $this->assertStringContainsString('widget', $e->getMessage());
            $this->assertStringContainsString('42', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // saveEntity
    // -------------------------------------------------------------------------

    public function testSaveEntityReturnsEntityOnSuccess(): void
    {
        $result = $this->repository->save($this->entity);

        $this->assertSame($this->entity, $result);
    }

    public function testSaveEntityCallsResourceModelSave(): void
    {
        $this->resourceModel->expects($this->once())
            ->method('save')
            ->with($this->entity);

        $this->repository->save($this->entity);
    }

    public function testSaveEntityThrowsCouldNotSaveExceptionOnFailure(): void
    {
        $this->expectException(CouldNotSaveException::class);

        $this->resourceModel->method('save')
            ->willThrowException(new \Exception('DB connection lost'));

        $this->repository->save($this->entity);
    }

    public function testSaveEntityExceptionMessageIncludesEntityLabelAndOriginalMessage(): void
    {
        $this->resourceModel->method('save')
            ->willThrowException(new \Exception('duplicate entry'));

        try {
            $this->repository->save($this->entity);
            $this->fail('Expected CouldNotSaveException');
        } catch (CouldNotSaveException $e) {
            $this->assertStringContainsString('widget', $e->getMessage());
            $this->assertStringContainsString('duplicate entry', $e->getMessage());
        }
    }

    public function testSaveEntityWrapsOriginalExceptionAsPrevious(): void
    {
        $original = new \Exception('original error');
        $this->resourceModel->method('save')->willThrowException($original);

        try {
            $this->repository->save($this->entity);
        } catch (CouldNotSaveException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // deleteEntity
    // -------------------------------------------------------------------------

    public function testDeleteEntityReturnsTrueOnSuccess(): void
    {
        $this->assertTrue($this->repository->delete($this->entity));
    }

    public function testDeleteEntityCallsResourceModelDelete(): void
    {
        $this->resourceModel->expects($this->once())
            ->method('delete')
            ->with($this->entity);

        $this->repository->delete($this->entity);
    }

    public function testDeleteEntityThrowsCouldNotDeleteExceptionOnFailure(): void
    {
        $this->expectException(CouldNotDeleteException::class);

        $this->resourceModel->method('delete')
            ->willThrowException(new \Exception('constraint violation'));

        $this->repository->delete($this->entity);
    }

    public function testDeleteEntityExceptionMessageIncludesEntityLabelAndOriginalMessage(): void
    {
        $this->resourceModel->method('delete')
            ->willThrowException(new \Exception('foreign key constraint'));

        try {
            $this->repository->delete($this->entity);
            $this->fail('Expected CouldNotDeleteException');
        } catch (CouldNotDeleteException $e) {
            $this->assertStringContainsString('widget', $e->getMessage());
            $this->assertStringContainsString('foreign key constraint', $e->getMessage());
        }
    }

    public function testDeleteEntityWrapsOriginalExceptionAsPrevious(): void
    {
        $original = new \Exception('original error');
        $this->resourceModel->method('delete')->willThrowException($original);

        try {
            $this->repository->delete($this->entity);
        } catch (CouldNotDeleteException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // performGetList
    // -------------------------------------------------------------------------

    public function testPerformGetListAppliesSearchCriteriaToCollection(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);

        $this->collectionProcessor->expects($this->once())
            ->method('process')
            ->with($searchCriteria, $this->collection);

        $this->searchResults->method('setSearchCriteria')->willReturnSelf();
        $this->searchResults->method('setItems')->willReturnSelf();
        $this->searchResults->method('setTotalCount')->willReturnSelf();
        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);

        $this->repository->getList($searchCriteria);
    }

    public function testPerformGetListSetsItemsOnSearchResults(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $items = [$this->createMock(AbstractModel::class)];

        $this->collection->method('getItems')->willReturn($items);
        $this->collection->method('getSize')->willReturn(1);

        $this->searchResults->method('setSearchCriteria')->willReturnSelf();
        $this->searchResults->method('setTotalCount')->willReturnSelf();
        $this->searchResults->expects($this->once())
            ->method('setItems')
            ->with($items)
            ->willReturnSelf();

        $this->repository->getList($searchCriteria);
    }

    public function testPerformGetListSetsTotalCountOnSearchResults(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);

        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(42);

        $this->searchResults->method('setSearchCriteria')->willReturnSelf();
        $this->searchResults->method('setItems')->willReturnSelf();
        $this->searchResults->expects($this->once())
            ->method('setTotalCount')
            ->with(42)
            ->willReturnSelf();

        $this->repository->getList($searchCriteria);
    }

    public function testPerformGetListSetsSearchCriteriaOnResults(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);

        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);

        $this->searchResults->method('setItems')->willReturnSelf();
        $this->searchResults->method('setTotalCount')->willReturnSelf();
        $this->searchResults->expects($this->once())
            ->method('setSearchCriteria')
            ->with($searchCriteria)
            ->willReturnSelf();

        $this->repository->getList($searchCriteria);
    }

    public function testPerformGetListReturnsSearchResults(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);

        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);
        $this->searchResults->method('setSearchCriteria')->willReturnSelf();
        $this->searchResults->method('setItems')->willReturnSelf();
        $this->searchResults->method('setTotalCount')->willReturnSelf();

        $result = $this->repository->getList($searchCriteria);

        $this->assertSame($this->searchResults, $result);
    }
}
