<?php
/**
 * Overrides Magento Tax Rate Source to load all rates without pagination.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Model\Rate;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Convert\DataObject as Converter;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Model\Rate\Provider as RateProvider;
use Magento\Framework\Api\SortOrderBuilder;

class Source extends \Magento\Tax\Model\Rate\Source
{
    protected $sortOrderBuilder;

    public function __construct(
        TaxRateRepositoryInterface $taxRateRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Converter $converter,
        RateProvider $rateProvider = null,
        SortOrderBuilder $sortOrderBuilder
    ) {
        parent::__construct($taxRateRepository, $searchCriteriaBuilder, $converter, $rateProvider);
        $this->sortOrderBuilder = $sortOrderBuilder;
    }

    public function toOptionArray()
    {
        if (!$this->options) {
            $sortOrder = $this->sortOrderBuilder
                ->setField('code')
                ->setDirection(\Magento\Framework\Api\SortOrder::SORT_ASC)
                ->create();

            $searchCriteria = $this->searchCriteriaBuilder
                ->addSortOrder($sortOrder)
                ->setPageSize(0)
                ->setCurrentPage(1)
                ->create();

            $this->options = $this->rateProvider->toOptionArray($searchCriteria);
        }

        return $this->options;
    }
}
