<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Api;

use Insead\TaxApi\Api\Data\TaxResponseInterface;

/**
 * Tax Calculation Service Contract — v2
 *
 * Magento acts as a pure tax engine. The product catalogue lives in external systems.
 *
 * =====================================================================
 * HOW THE THREE TAX ENGINE INPUTS ARE RESOLVED
 * =====================================================================
 *
 * 1. PRODUCT TAX CLASS — via SKU lookup
 *    Pattern: {legalEntity}_{deliveryModePrefix}_{productFamily}_{durationSuffix}_{programmeDeliveryLocation}
 *
 *    Delivery mode prefix:
 *      Online       → OL
 *      F2F          → IP
 *      Live Virtual → OL
 *
 *    Duration suffix (OEP and CSP + F2F only):
 *      duration >= 7 days → GT1W
 *      duration <  7 days → LT1W
 *      missing/null/zero  → GT1W (default)
 *      all other cases    → NA
 *
 *    Example SKUs:
 *      FBL + OEP + F2F + 10 days + FBL campus → FBL_IP_OEP_GT1W_FBL
 *      SGP + CSP + F2F +  3 days + SGP campus → SGP_IP_CSP_LT1W_SGP
 *      FBL + OEP + Online        + NA         → FBL_OL_OEP_NA_NA
 *      UAE + CST + F2F           + UAE campus → UAE_IP_CST_NA_UAE
 *      FBL + OOP + Online        + NA         → FBL_OL_OOP_NA_NA
 *
 * 2. CUSTOMER TAX CLASS — resolution priority (top wins):
 *
 *    B2B:
 *      1. taxStatus = "Tax Exempt"                      → B2B_EXEMPT (wins over everything, all entities)
 *      2. legalEntity=SGP + gstDeclarationAccepted=true → B2B_GST_EXEMPT (any programme)
 *      3. All other B2B                                 → B2B
 *
 *    B2C: always → B2C
 *      legalEntity=SGP + OOP + outsideSg=false → billingCountry overridden to SG (9% GST)
 *      outsideSg=null/true or non-OOP          → billingCountry used as sent
 *
 *    gstDeclarationAccepted and outsideSg are ignored when legalEntity ≠ SGP.
 *
 * 3. BILLING COUNTRY
 *    Passed directly from billingCountry — no override in v2.
 *
 * =====================================================================
 * FALLBACK RATES (when no Magento rule matches)
 * =====================================================================
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
     *   - product_family (string) Programme family: OEP, CSP, CST, DP, OOP
     *   - delivery_mode  (string) Delivery mode: Online, F2F, Live Virtual
     *   - price          (float)  Unit price >= 0
     *   - qty            (float)  Quantity > 0
     *
     * Line item optional fields:
     *   - duration (int) Programme duration in days. Required for OEP/CSP + F2F.
     *                    Missing/null/zero defaults to GT1W. Ignored for all other combinations.
     *   - name     (string) Product name — stored in log only
     *
     * @param string      $legalEntity               Invoicing entity: FBL, SGP, UAE, USA
     * @param string      $customerType              Base customer type: B2B or B2C
     * @param string      $billingCountry            ISO-2 billing country — primary tax engine input
     * @param float       $subtotal                  Total before tax (sum of all line items)
     * @param string      $currency                  ISO-3 currency code
     * @param \Insead\TaxApi\Api\Data\LineItemInterface[] $lineItems At least one item
     * @param string      $programmeDeliveryLocation Campus: FBL, SGP, UAE, USA, or NA
     * @param string|null $taxStatus                B2B tax status: Tax Registered, Not Tax Registered, Tax Exempt
     * @param bool|null   $gstDeclarationAccepted   SGP + B2B only. true = customer accepted GST declaration → B2B_GST_EXEMPT (0%). Any programme.
     * @param bool|null   $outsideSg                SGP + B2C + OOP only. false = participant inside SG → billingCountry overridden to SG → 9% GST. null treated as true.
     * @param string|null $vatNumber                VAT / tax identification number — audit log only
     * @param string|null $billingSystem            Source system e.g. PeopleSoft, Salesforce — audit log only
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
        ?string $taxStatus = null,
        ?bool $gstDeclarationAccepted = null,
        ?bool $outsideSg = null,
        ?string $vatNumber = null,
        ?string $billingSystem = null
    ): TaxResponseInterface;

    /**
     * Simplified tax calculation endpoint for external billing systems.
     *
     * The endpoint accepts only the fields needed by the billing layer and
     * reuses the main engine implementation under the hood.
     *
     * @param string $legalEntity
     * @param string $customerType
     * @param string $billingCountry
     * @param float $grandTotal
     * @param bool $isQuote
     * @param \Insead\TaxApi\Api\Data\LineItemInterface[] $lineItems
     * @return \Insead\TaxApi\Api\Data\TaxResponseInterface
     */
    public function calculateTaxSimple(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        float $grandTotal,
        bool $isQuote,
        array $lineItems
    );
}
