<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: treats refunded as closed; expires only returns that wait on
 * the customer and never withdrawal returns; walks matches in batches of 500.
 */
declare(strict_types=1);

namespace Magenx\Rma\Console\Command;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    const OPTION_DAYS = 'days';
    const OPTION_DRY_RUN = 'dry-run';
    const BATCH_SIZE = 500;
    const CLOSED_STATUSES = StatusCodes::FINAL_CODES;

    /**
     * Statuses where the next step is the customer's. Only these can expire:
     * a return waiting on staff (new request, shipped, received) is never
     * canceled on the customer's behalf, because that would free its units
     * for a new return while the merchant may hold the goods.
     */
    const EXPIRABLE_STATUSES = [
        StatusCodes::NEED_DETAILS,
        StatusCodes::APPROVED,
    ];

    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly FilterBuilder $filterBuilder,
        protected readonly FilterGroupBuilder $filterGroupBuilder,
        protected readonly StatusResolver $statusResolver
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('rma:cleanup')
            ->setDescription('Cancel inactive RMA requests that wait on the customer (need details, approved; never withdrawals)')
            ->addOption(
                self::OPTION_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days of inactivity after which to cancel RMAs'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Show which RMAs would be canceled without actually canceling them'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption(self::OPTION_DAYS);

        if (!is_numeric($days) || (int)$days <= 0) {
            $output->writeln('<error>The --days option is required and must be a positive integer.</error>');
            return Cli::RETURN_FAILURE;
        }

        $days = (int)$days;
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);

        try {
            $canceledStatusId = $this->statusResolver->getRequiredIdByCode(StatusCodes::CANCELED_BY_CUSTOMER);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $expirableStatusIds = $this->statusResolver->getIdsByCodes(self::EXPIRABLE_STATUSES);

        if (empty($expirableStatusIds)) {
            $output->writeln('<error>Could not resolve expirable status IDs.</error>');
            return Cli::RETURN_FAILURE;
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Filter: updated_at <= cutoff date
        $dateFilter = $this->filterBuilder
            ->setField(RMAInterface::UPDATED_AT)
            ->setConditionType('lteq')
            ->setValue($cutoffDate)
            ->create();

        // Filter: only returns waiting on the customer
        $statusFilter = $this->filterBuilder
            ->setField(RMAInterface::STATUS_ID)
            ->setConditionType('in')
            ->setValue($expirableStatusIds)
            ->create();

        // Filter: never a withdrawal return; it carries a legal right, staff close it
        $withdrawalFilter = $this->filterBuilder
            ->setField(RMAInterface::IS_WITHDRAWAL)
            ->setConditionType('eq')
            ->setValue(0)
            ->create();

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $count = 0;
        $seen = 0;
        $lastId = 0;

        // Walk the matches in id order, one batch at a time, so a large backlog is never
        // loaded at once. The id cursor (not a page number) stays correct while canceled
        // rows drop out of the status filter and failed ones stay in it.
        do {
            $cursorFilter = $this->filterBuilder
                ->setField(RMAInterface::ENTITY_ID)
                ->setConditionType('gt')
                ->setValue($lastId)
                ->create();

            $searchCriteria = $this->searchCriteriaBuilder->create();
            $searchCriteria->setFilterGroups([
                $this->filterGroupBuilder->setFilters([$dateFilter])->create(),
                $this->filterGroupBuilder->setFilters([$statusFilter])->create(),
                $this->filterGroupBuilder->setFilters([$withdrawalFilter])->create(),
                $this->filterGroupBuilder->setFilters([$cursorFilter])->create(),
            ]);
            $searchCriteria->setSortOrders([
                new SortOrder([SortOrder::FIELD => RMAInterface::ENTITY_ID, SortOrder::DIRECTION => SortOrder::SORT_ASC]),
            ]);
            $searchCriteria->setPageSize(self::BATCH_SIZE);
            $searchCriteria->setCurrentPage(1);

            $batch = $this->rmaRepository->getList($searchCriteria)->getItems();

            foreach ($batch as $rma) {
                $seen++;
                $lastId = max($lastId, (int)$rma->getEntityId());
                $output->writeln(sprintf(
                    '%s<comment>Canceling RMA %s (last updated: %s)</comment>',
                    $prefix,
                    $rma->getIncrementId(),
                    $rma->getUpdatedAt()
                ));

                if (!$dryRun) {
                    try {
                        $rma->setStatusId($canceledStatusId);
                        $this->rmaRepository->save($rma);
                    } catch (LocalizedException $e) {
                        $output->writeln(sprintf(
                            '<error>Failed to cancel RMA %s: %s</error>',
                            $rma->getIncrementId(),
                            $e->getMessage()
                        ));
                        continue;
                    }
                }

                $count++;
            }
        } while (count($batch) === self::BATCH_SIZE);

        if ($seen === 0) {
            $output->writeln('<info>No RMA to clean up.</info>');
            return Cli::RETURN_SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%s%d RMA(s) %s.</info>',
            $prefix,
            $count,
            $dryRun ? 'would be canceled' : 'canceled'
        ));

        return Cli::RETURN_SUCCESS;
    }
}
