<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Api;

use Insead\TaxApi\Api\Data\TaxResponseInterface;

/**
 * Tax Calculation Service Contract
 *
 * Magento acts as a pure tax engine. The product catalogue lives in external systems.
 *
 * How the three tax engine inputs are resolved:
 *
 *   PRODUCT TAX CLASS:
 *     Constructed SKU = {legalEntity}_{tax_product_code}_{programmeDeliveryLocation}
 *     e.g. SGP_OL_OOP_NA_NA, FBL_IP_OEP_SHORT_FBL
 *     → ProductRepository::get(SKU) → product tax class
 *
 *   CUSTOMER TAX CLASS:
 *     Derived from customerType + isValidVat + gstExempt
 *     e.g. B2B + isValidVat=true  → B2B_VAT
 *          B2B + gstExempt=true   → B2B_GST_EXEMPT
 *          B2B + both=true        → B2B_VAT_GST_EXEMPT
 *     isTaxRegistered is captured and stored for future rule use.
 *
 *   BILLING COUNTRY (passed to tax engine):
 *     Default: billingCountry as supplied
 *     Override: if legalEntity=SGP AND participantCountry=SG → use SG
 *
 * LEGAL ENTITIES & FALLBACK RATES (when no rule matches):
 *   FBL (Fontainebleau/France) → 20%
 *   SGP (Singapore)            →  9%
 *   UAE (Abu Dhabi)            →  5%
 *   USA (North America)        →  0%
 */
interface TaxCalculationInterface
{
    /**
     * Calculate tax for external billing systems.
     *
     * Line item required fields:
     *   - tax_product_code (string) Structured code e.g. "OL_OOP_NA", "IP_OEP_SHORT"
     *   - price            (float)  Unit price >= 0
     *   - qty              (int)    Quantity > 0
     *
     * Line item optional fields:
     *   - sku  (string) External system SKU — stored in log only
     *   - name (string) Product name — stored in log only
     *
     * @param string      $legalEntity                Invoicing entity: FBL, SGP, UAE, USA
     * @param string      $customerType               Base customer type: B2B or B2C
     * @param string      $billingCountry             ISO-2 billing country — primary tax engine input
     * @param float       $subtotal                   Total before tax (sum of all line items)
     * @param string      $currency                   ISO-3 currency code
     * @param \Insead\TaxApi\Api\Data\LineItemInterface[] $lineItems At least one item with tax_product_code, price, qty
     * @param string      $programmeDeliveryLocation  Campus: FBL, SGP, UAE, USA, or NA
     * @param string|null $programmeType              Programme type: OOP, OEP, CSP, CASES, FOOD etc. Used for SGP billing override.
     * @param bool|null   $isValidVat                 Customer holds a valid VAT registration (optional)
     * @param bool|null   $gstExempt                  Customer is GST exempt — Singapore consent form (optional)
     * @param bool|null   $isTaxRegistered            Customer is tax registered in their country (optional)
     * @param string|null $participantCountry         ISO-2 where participant is present during programme
     * @param string|null $vatNumber                  VAT / tax identification number
     * @param string|null $billingSystem              Source system e.g. PeopleSoft, Salesforce
     *
     * @return \Insead\TaxApi\Api\Data\TaxResponseInterface
     */
    public function calculateTax(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        float $subtotal,
        string $currency,
        array $lineItems,
        string $programmeDeliveryLocation,
        ?string $programmeType = null,
        ?bool $isValidVat = null,
        ?bool $gstExempt = null,
        ?bool $isTaxRegistered = null,
        ?string $participantCountry = null,
        ?string $vatNumber = null,
        ?string $billingSystem = null
    ): TaxResponseInterface;
}
