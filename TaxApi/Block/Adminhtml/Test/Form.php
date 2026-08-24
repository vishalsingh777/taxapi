<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
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
        'NA'  => 'NA — Not applicable (Online, OOP)',
        'FBL' => 'FBL — Fontainebleau campus',
        'SGP' => 'SGP — Singapore campus',
        'UAE' => 'UAE — Abu Dhabi campus',
        'USA' => 'USA — North America campus',
    ];

    private const TAX_STATUSES = [
        ''                    => '-- Not specified --',
        'Tax Registered'      => 'Tax Registered',
        'Not Tax Registered'  => 'Not Tax Registered',
        'Tax Exempt'          => 'Tax Exempt',
    ];

    private const PRODUCT_FAMILIES = [
        'OEP' => 'OEP — Open Enrollment Programme',
        'CSP' => 'CSP — Customized Solutions Programme',
        'CST' => 'CST — Custom Short Training',
        'DP'  => 'DP — Degree Programme',
        'OOP' => 'OOP — Online Open Programme',
    ];

    private const DELIVERY_MODES = [
        'Online'       => 'Online',
        'F2F'          => 'F2F — Face to Face',
        'Live Virtual' => 'Live Virtual',
    ];

    public function __construct(
        Context $context,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<string, string> */
    public function getLegalEntities(): array { return self::LEGAL_ENTITIES; }

    /** @return array<string, string> */
    public function getDeliveryLocations(): array { return self::DELIVERY_LOCATIONS; }

    /** @return array<string, string> */
    public function getTaxStatuses(): array { return self::TAX_STATUSES; }

/** @return array<string, string> */
    public function getProductFamilies(): array { return self::PRODUCT_FAMILIES; }

    /** @return array<string, string> */
    public function getDeliveryModes(): array { return self::DELIVERY_MODES; }

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
