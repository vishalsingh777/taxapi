<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;

class Form extends Template
{
    private const LEGAL_ENTITIES = [
        'FBL' => 'FBL — Fontainebleau (France)',
        'SGP' => 'SGP — Singapore',
        'UAE' => 'UAE — Abu Dhabi',
        'USA' => 'USA — North America',
    ];

    private const DELIVERY_LOCATIONS = [
        'NA'  => 'NA — Not applicable (OOP, Cases, Food)',
        'FBL' => 'FBL — Fontainebleau campus',
        'SGP' => 'SGP — Singapore campus',
        'UAE' => 'UAE — Abu Dhabi campus',
        'USA' => 'USA — North America campus',
    ];

    public function __construct(
        Context $context,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly GroupRepositoryInterface $customerGroupRepository,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<string, string> */
    public function getLegalEntities(): array
    {
        return self::LEGAL_ENTITIES;
    }

    /** @return array<string, string> */
    public function getDeliveryLocations(): array
    {
        return self::DELIVERY_LOCATIONS;
    }

    /**
     * Customer tax class names from customer groups.
     *
     * @return array<string, string>
     */
    public function getCustomerTaxClasses(): array
    {
        $classes = [];
        try {
            $groups = $this->customerGroupRepository
                ->getList($this->searchCriteriaBuilder->create())
                ->getItems();

            foreach ($groups as $group) {
                try {
                    $taxClass       = $this->taxClassRepository->get($group->getTaxClassId());
                    $name           = $taxClass->getClassName();
                    $classes[$name] = $name;
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            // Return empty on error — form still renders
        }

        return $classes;
    }

    /** @return array<string, string> */
    public function getCountries(): array
    {
        $countries  = [];
        $collection = $this->countryCollectionFactory->create();
        foreach ($collection as $country) {
            $countries[$country->getCountryId()] = $country->getName();
        }
        return $countries;
    }

    public function getCalculateUrl(): string
    {
        return $this->getUrl('insead_taxapi/test/calculate');
    }
}
