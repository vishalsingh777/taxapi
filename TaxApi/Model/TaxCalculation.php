<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Model;

use Insead\TaxApi\Api\TaxCalculationInterface;
use Insead\TaxApi\Api\Data\TaxResponseInterface;
use Insead\TaxApi\Api\Data\LineItemInterface;
use Insead\TaxApi\Model\Data\TaxResponseFactory;
use Insead\TaxApi\Model\Data\LineItemResultFactory;
use Insead\TaxApi\Model\TaxCalculationLogFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Tax\Api\TaxCalculationInterface as MagentoTaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\TaxClass\Key as TaxClassKey;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Psr\Log\LoggerInterface;
use Magento\Tax\Api\TaxRateRepositoryInterface;

/**
 * INSEAD Tax Calculation Service — v2
 *
 * Magento acts as a pure tax engine. No product catalogue exists here —
 * only generic "tax carrier" products keyed by a constructed SKU.
 *
 * =====================================================================
 * HOW IT WORKS — THREE INPUTS TO THE TAX ENGINE
 * =====================================================================
 *
 * Magento's tax engine needs exactly three inputs to match a rule:
 *   Customer Tax Class  +  Product Tax Class  +  Billing Country
 *
 * This service derives all three from the incoming API payload.
 *
 * ---------------------------------------------------------------------
 * INPUT 1: PRODUCT TAX CLASS — via SKU lookup
 * ---------------------------------------------------------------------
 * Pattern:
 *   {legalEntity}_{deliveryModePrefix}_{productFamily}_{durationSuffix}_{programmeDeliveryLocation}
 *
 * Delivery mode prefix:
 *   Online       → OL
 *   Live Virtual → OL  (treated same as Online)
 *   F2F          → IP  (In-Person)
 *
 * Duration suffix (only for OEP and CSP with F2F delivery):
 *   duration >= 7 days → GT1W
 *   duration <  7 days → LT1W
 *   missing/null/zero  → GT1W (safe default)
 *   all other cases    → NA
 *
 * Examples:
 *   SGP + OOP + Online + NA        → SGP_OL_OOP_NA_NA
 *   FBL + OEP + F2F + 10d + FBL   → FBL_IP_OEP_GT1W_FBL
 *   SGP + CSP + F2F + 3d  + SGP   → SGP_IP_CSP_LT1W_SGP
 *   UAE + CST + F2F + NA  + UAE   → UAE_IP_CST_NA_UAE
 *
 * If no Magento product exists for the constructed SKU → error TAX_SKU_NOT_FOUND.
 *
 * ---------------------------------------------------------------------
 * INPUT 2: CUSTOMER TAX CLASS — priority resolution
 * ---------------------------------------------------------------------
 * B2B (checked in strict priority order — first match wins):
 *   Priority 1: taxStatus = "Tax Exempt"
 *               → B2B_EXEMPT (overrides everything, all entities)
 *
 *   Priority 2: legalEntity = SGP AND gstDeclarationAccepted = true
 *               → B2B_GST_EXEMPT (GST declaration letter provided — any programme)
 *
 *   Priority 3: all other B2B
 *               → B2B
 *
 * B2C:
 *   Always → B2C (taxStatus and gstDeclarationAccepted are ignored)
 *
 * ---------------------------------------------------------------------
 * INPUT 3: BILLING COUNTRY — with one override
 * ---------------------------------------------------------------------
 * Default: billingCountry as sent in the request.
 *
 * SGP B2C OOP override (outside_sg rule):
 *   IF legalEntity=SGP AND customerType=B2C AND product_family=OOP AND outsideSg=false
 *   → billingCountry overridden to SG internally
 *   → 9% SGP GST applies (participant is physically inside Singapore)
 *   outsideSg=null is treated as true (use actual billing country — safe default).
 *
 * =====================================================================
 * ERROR HANDLING — NO FALLBACK RATES
 * =====================================================================
 * If no tax rule matches, the API returns a standard error response with
 * error code TAX_RULE_NOT_FOUND. There are no fallback rates.
 * The billing system must handle the error and alert the tax team.
 *
 * Error codes returned in the message field:
 *   TAX_INVALID_INPUT      — Request payload validation failed
 *   TAX_SKU_NOT_FOUND      — Magento generic product missing for constructed SKU
 *   TAX_CLASS_NOT_FOUND    — Customer tax class not configured in Magento
 *   TAX_RULE_NOT_FOUND     — No matching tax rule for this combination
 *   TAX_ENGINE_ERROR       — Unexpected internal error
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxCalculation implements TaxCalculationInterface
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * Valid legal entities accepted by the API.
     * Each maps to a Magento store and a set of tax rules.
     */
    private const LEGAL_ENTITIES = ['FBL', 'SGP', 'UAE', 'USA'];

    /**
     * Valid programme family codes.
     * Used as the middle segment of the constructed Magento SKU.
     */
    private const PRODUCT_FAMILIES = ['OEP', 'CSP', 'CST', 'DP', 'OOP'];

    /**
     * Valid delivery modes (normalised to uppercase for comparison).
     */
    private const DELIVERY_MODES = ['ONLINE', 'F2F', 'LIVE VIRTUAL'];

    /**
     * Delivery modes that produce the OL (Online) prefix in the SKU.
     * F2F is the only mode that produces IP (In-Person).
     */
    private const DELIVERY_MODE_OL = ['ONLINE', 'LIVE VIRTUAL'];

    /**
     * Product families where duration drives the SKU suffix (GT1W vs LT1W).
     * All other families always use NA regardless of duration.
     */
    private const DURATION_FAMILIES = ['OEP', 'CSP'];

    /**
     * Duration threshold in days for GT1W vs LT1W.
     * >= 7 days → GT1W, < 7 days → LT1W, missing → GT1W (safe default).
     */
    private const DURATION_THRESHOLD_DAYS = 7;

    /**
     * Tax status value that triggers B2B_EXEMPT — the highest priority class.
     * Stored uppercase for case-insensitive comparison after normalisation.
     */
    private const TAX_STATUS_EXEMPT = 'TAX EXEMPT';

    /**
     * Magento customer tax class names.
     * These must exist in Stores → Taxes → Tax Classes → Customer Tax Classes.
     */
    private const CLASS_B2B            = 'B2B';
    private const CLASS_B2B_EXEMPT     = 'B2B_EXEMPT';
    private const CLASS_B2B_GST_EXEMPT = 'B2B_GST_EXEMPT';
    private const CLASS_B2C            = 'B2C';

    /**
     * API response status values.
     * No "warning" status — every non-success outcome is an error.
     */
    private const STATUS_SUCCESS = 'success';
    private const STATUS_ERROR   = 'error';

    /**
     * Standard error codes returned in the API response message field.
     * The billing system (Salesforce) uses these codes to classify errors.
     */
    private const ERR_INVALID_INPUT   = 'TAX_INVALID_INPUT';
    private const ERR_SKU_NOT_FOUND   = 'TAX_SKU_NOT_FOUND';
    private const ERR_CLASS_NOT_FOUND = 'TAX_CLASS_NOT_FOUND';
    private const ERR_RULE_NOT_FOUND  = 'TAX_RULE_NOT_FOUND';
    private const ERR_ENGINE_ERROR    = 'TAX_ENGINE_ERROR';

    /**
     * Magento tax class type constant for customer class lookups.
     */
    private const TAX_CLASS_TYPE_CUSTOMER = 'CUSTOMER';

    private const EU_OSS_COMMENT_PREFIX = 'Local EU VAT @';

    // =========================================================================
    // In-request caches
    // =========================================================================

    /**
     * Caches customer tax class ID lookups by class name.
     * Prevents duplicate DB queries within the same API call.
     *
     * @var array<string, int|null>
     */
    private array $customerTaxClassCache = [];

    /**
     * Caches product tax class ID lookups by Magento SKU.
     * Prevents duplicate ProductRepository::get() calls for the same SKU.
     *
     * @var array<string, int|null>
     */
    private array $productTaxClassCache = [];

    /**
     * Caches virtual billing address objects by country ID.
     * The tax engine requires an address object — we use a minimal virtual one.
     *
     * @var array<string, \Magento\Customer\Api\Data\AddressInterface>
     */
    private array $virtualAddressCache = [];

    /**
     * @var array<string, int[]>
     */
    private array $countryRateIdsCache = [];

    // =========================================================================
    // Constructor
    // =========================================================================

    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private readonly MagentoTaxCalculationInterface   $taxCalculation,
        private readonly QuoteDetailsInterfaceFactory     $quoteDetailsFactory,
        private readonly QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        private readonly TaxClassKeyInterfaceFactory      $taxClassKeyFactory,
        private readonly AddressInterfaceFactory          $addressFactory,
        private readonly RegionInterfaceFactory           $regionFactory,
        private readonly TaxClassRepositoryInterface      $taxClassRepository,
        private readonly TaxRuleRepositoryInterface       $taxRuleRepository,
        private readonly TaxRateRepositoryInterface       $taxRateRepository,
        private readonly SearchCriteriaBuilderFactory     $searchCriteriaBuilderFactory,
        private readonly ProductRepositoryInterface       $productRepository,
        private readonly LoggerInterface                  $logger,
        private readonly TaxCalculationLogFactory         $taxLogFactory,
        private readonly TaxResponseFactory               $taxResponseFactory,
        private readonly LineItemResultFactory            $lineItemResultFactory
    ) {
    }

    // =========================================================================
    // Public API — entry point
    // =========================================================================

    /** @inheritDoc */
    public function calculateTaxSimple(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        float $grandTotal,
        bool $isQuote,
        array $lineItems
    ): TaxResponseInterface {
        $lineItemArrays = [];
        $billingSystem = $isQuote ? 'simple_quote' : 'simple_order';

        try {
            // STEP 0 — Normalise all string inputs to uppercase.
            $legalEntity               = strtoupper(trim($legalEntity));
            $customerType              = strtoupper(trim($customerType));
            $billingCountry            = strtoupper(trim($billingCountry));
            $programmeDeliveryLocation = $legalEntity;

            $currencyMap = [
                'FBL' => 'EUR',
                'SGP' => 'SGD',
                'UAE' => 'AED',
                'USA' => 'USD',
            ];
            $currency = $currencyMap[$legalEntity] ?? 'EUR';

            // Convert LineItemInterface DTOs or raw arrays to normalised plain arrays.
            $lineItemArrays = $this->normaliseLineItems($lineItems);

            // STEP 1 — Validate all input fields before doing any DB lookups.
            $validation = $this->validateInput(
                $legalEntity,
                $customerType,
                $billingCountry,
                $grandTotal,
                $currency,
                $lineItemArrays,
                $programmeDeliveryLocation
            );

            if (!$validation['valid']) {
                $response = $this->buildErrorResponse(
                    self::ERR_INVALID_INPUT,
                    $validation['message'],
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $grandTotal, $currency, $lineItemArrays, $response,
                    null, null, null, null, $billingSystem
                );
                return $response;
            }

            // STEP 2 — Resolve the Magento customer tax class name.
            $resolvedCustomerClass = $this->resolveCustomerTaxClassName(
                $customerType,
                null,
                null,
                $legalEntity
            );

            $customerTaxClassId = $this->getCustomerTaxClassId($resolvedCustomerClass);

            if ($customerTaxClassId === null) {
                $response = $this->buildErrorResponse(
                    self::ERR_CLASS_NOT_FOUND,
                    "Customer tax class '{$resolvedCustomerClass}' not found in Magento. "
                    . "Create it at Stores → Taxes → Tax Classes → Customer Tax Classes.",
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $grandTotal, $currency, $lineItemArrays, $response,
                    null, null, null, null, $billingSystem
                );
                return $response;
            }

            // STEP 3 — Build the Magento SKU for each line item and look up
            // the product tax class ID.
            $resolvedLineItems = [];
            foreach ($lineItemArrays as $index => $item) {
                $sku = $this->buildSku(
                    $legalEntity,
                    $item['product_family'],
                    $item['delivery_mode'],
                    $item['duration'],
                    $programmeDeliveryLocation
                );

                $productTaxClassId = $this->getProductTaxClassIdBySku($sku);

                if ($productTaxClassId === null) {
                    $response = $this->buildErrorResponse(
                        self::ERR_SKU_NOT_FOUND,
                        "Constructed SKU '{$sku}' (line item {$index}) not found in Magento. "
                        . "Create a generic product with SKU = '{$sku}' and assign a product tax class.",
                        400
                    );
                    $this->logCalculation(
                        $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                        $grandTotal, $currency, $lineItemArrays, $response,
                        null, null, null, null, $billingSystem
                    );
                    return $response;
                }

                $resolvedLineItems[] = array_merge($item, [
                    '_sku'                  => $sku,
                    '_product_tax_class_id' => $productTaxClassId,
                ]);
            }

            // STEP 4 — Resolve the effective billing country.
            $effectiveBillingCountry = $this->resolveEffectiveBillingCountry(
                $legalEntity,
                $customerType,
                $billingCountry,
                null,
                $lineItemArrays
            );

            // STEP 5 — Pass the three resolved inputs to Magento's native tax engine.
            $taxDetails = $this->runTaxEngine(
                $customerTaxClassId,
                $effectiveBillingCountry,
                $resolvedLineItems
            );

            if ($taxDetails === null) {
                $response = $this->buildErrorResponse(
                    self::ERR_RULE_NOT_FOUND,
                    "TAX_RULE_NOT_FOUND — No tax rule configured for: "
                    . "customerClass='{$resolvedCustomerClass}', "
                    . "billingCountry='{$effectiveBillingCountry}', "
                    . "legalEntity='{$legalEntity}'. "
                    . "Please contact the tax team.",
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $grandTotal, $currency, $lineItemArrays, $response,
                    null, null, null, null, $billingSystem
                );
                return $response;
            }

            // STEP 6 — Build the success response.
            $response = $this->buildSuccessResponse(
                $taxDetails,
                $currency,
                $customerTaxClassId,
                $effectiveBillingCountry,
                $resolvedLineItems
            );

            $this->logCalculation(
                $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                $grandTotal, $currency, $lineItemArrays, $response,
                null, null, null, null, $billingSystem
            );

            return $response;

        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: unexpected engine error in calculateTaxSimple: ' . $e->getMessage(),
                [
                    'exception'       => $e,
                    'legal_entity'    => $legalEntity ?? 'UNKNOWN',
                    'billing_country' => $billingCountry ?? 'UNKNOWN',
                ]
            );

            $response = $this->buildErrorResponse(
                self::ERR_ENGINE_ERROR,
                'TAX_ENGINE_ERROR — An unexpected error occurred. Please contact the tax team. '
                . 'Ref: ' . $e->getMessage(),
                500
            );

            $this->logCalculation(
                $legalEntity ?? 'UNKNOWN',
                $customerType ?? 'UNKNOWN',
                $billingCountry ?? 'UNKNOWN',
                $programmeDeliveryLocation ?? 'NA',
                $grandTotal ?? 0.0,
                $currency ?? 'EUR',
                $lineItemArrays,
                $response,
                null,
                null,
                null,
                null,
                isset($isQuote) ? ($isQuote ? 'simple_quote' : 'simple_order') : null
            );

            return $response;
        }
    }

    /** @inheritDoc */
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
    ): TaxResponseInterface {

        // Initialised here so the catch block can always call logCalculation()
        // even if the exception occurs before $lineItemArrays is populated.
        $lineItemArrays = [];

        try {
            // -----------------------------------------------------------------
            // STEP 0 — Normalise all string inputs to uppercase.
            // The API is fully case-insensitive: "fbl", "FBL", "Fbl" are equal.
            // Boolean fields (gstDeclarationAccepted, outsideSg) need no normalisation.
            // -----------------------------------------------------------------
            $legalEntity               = strtoupper(trim($legalEntity));
            $customerType              = strtoupper(trim($customerType));
            $billingCountry            = strtoupper(trim($billingCountry));
            $currency                  = strtoupper(trim($currency));
            $programmeDeliveryLocation = strtoupper(trim($programmeDeliveryLocation));
            $taxStatus                 = $taxStatus !== null ? strtoupper(trim($taxStatus)) : null;

            // Convert LineItemInterface DTOs or raw arrays to normalised plain arrays.
            // This lets the rest of the service work with a single consistent format
            // regardless of whether the request came via WebAPI (DTO objects) or
            // the admin test form (plain arrays).
            $lineItemArrays = $this->normaliseLineItems($lineItems);

            // -----------------------------------------------------------------
            // STEP 1 — Validate all input fields before doing any DB lookups.
            // Returns early with TAX_INVALID_INPUT if anything is missing or malformed.
            // -----------------------------------------------------------------
            $validation = $this->validateInput(
                $legalEntity,
                $customerType,
                $billingCountry,
                $subtotal,
                $currency,
                $lineItemArrays,
                $programmeDeliveryLocation
            );

            if (!$validation['valid']) {
                $response = $this->buildErrorResponse(
                    self::ERR_INVALID_INPUT,
                    $validation['message'],
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $taxStatus, $gstDeclarationAccepted, $outsideSg, $vatNumber, $billingSystem
                );
                return $response;
            }

            // -----------------------------------------------------------------
            // STEP 2 — Resolve the Magento customer tax class name.
            // Priority order:
            //   1. taxStatus = Tax Exempt → B2B_EXEMPT (wins over everything)
            //   2. SGP + gstDeclarationAccepted=true → B2B_GST_EXEMPT
            //   3. Default → B2B
            //   B2C: always → B2C
            // Then look up the numeric class ID from Magento's tax_class table.
            // -----------------------------------------------------------------
            $resolvedCustomerClass = $this->resolveCustomerTaxClassName(
                $customerType,
                $taxStatus,
                $gstDeclarationAccepted,
                $legalEntity
            );

            $customerTaxClassId = $this->getCustomerTaxClassId($resolvedCustomerClass);

            if ($customerTaxClassId === null) {
                // The resolved class name does not exist in Magento.
                // This means the Magento setup is incomplete (Step 1 of setup guide).
                $response = $this->buildErrorResponse(
                    self::ERR_CLASS_NOT_FOUND,
                    "Customer tax class '{$resolvedCustomerClass}' not found in Magento. "
                    . "Create it at Stores → Taxes → Tax Classes → Customer Tax Classes.",
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $taxStatus, $gstDeclarationAccepted, $outsideSg, $vatNumber, $billingSystem
                );
                return $response;
            }

            // -----------------------------------------------------------------
            // STEP 3 — Build the Magento SKU for each line item and look up
            // the product tax class ID.
            //
            // SKU pattern:
            //   {legalEntity}_{deliveryModePrefix}_{productFamily}_{durationSuffix}_{pdl}
            //
            // If the product does not exist in Magento, return TAX_SKU_NOT_FOUND
            // with the exact missing SKU so the admin knows what to create.
            // -----------------------------------------------------------------
            $resolvedLineItems = [];

            foreach ($lineItemArrays as $index => $item) {
                $sku = $this->buildSku(
                    $legalEntity,
                    $item['product_family'],
                    $item['delivery_mode'],
                    $item['duration'],
                    $programmeDeliveryLocation
                );

                $productTaxClassId = $this->getProductTaxClassIdBySku($sku);

                if ($productTaxClassId === null) {
                    // SKU not found or has no tax class assigned.
                    // The admin must create a simple product with this SKU and assign
                    // the correct product tax class (Step 4 of setup guide).
                    $response = $this->buildErrorResponse(
                        self::ERR_SKU_NOT_FOUND,
                        "Constructed SKU '{$sku}' (line item {$index}) not found in Magento. "
                        . "Create a generic product with SKU = '{$sku}' and assign a product tax class.",
                        400
                    );
                    $this->logCalculation(
                        $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                        $subtotal, $currency, $lineItemArrays, $response,
                        $taxStatus, $gstDeclarationAccepted, $outsideSg, $vatNumber, $billingSystem
                    );
                    return $response;
                }

                // Store the resolved SKU and product tax class ID alongside the
                // original item data so buildSuccessResponse() can use them.
                $resolvedLineItems[] = array_merge($item, [
                    '_sku'                  => $sku,
                    '_product_tax_class_id' => $productTaxClassId,
                ]);
            }

            // -----------------------------------------------------------------
            // STEP 4 — Resolve the effective billing country.
            //
            // Normally the billingCountry from the request is used directly.
            //
            // SGP B2C OOP exception (outside_sg rule):
            //   If legalEntity=SGP AND customerType=B2C AND product_family=OOP
            //   AND outsideSg=false → override billingCountry to SG internally.
            //   This handles the case where a B2C customer is physically present
            //   in Singapore during the OOP programme — SGP 9% GST applies.
            //   outsideSg=null is treated as true (use actual billing country).
            // -----------------------------------------------------------------
            $effectiveBillingCountry = $this->resolveEffectiveBillingCountry(
                $legalEntity,
                $customerType,
                $billingCountry,
                $outsideSg,
                $lineItemArrays
            );

            // -----------------------------------------------------------------
            // STEP 5 — Pass the three resolved inputs to Magento's native tax engine.
            //   Input 1: Customer tax class ID (from Step 2)
            //   Input 2: Effective billing country (from Step 4)
            //   Input 3: Product tax class ID per item (from Step 3, inside QuoteDetails)
            //
            // The engine returns null if no configured tax rule matches.
            // This is not a fallback — it is a hard error (TAX_RULE_NOT_FOUND).
            // The admin must configure the missing rule (Step 6 of setup guide).
            // -----------------------------------------------------------------
            $taxDetails = $this->runTaxEngine(
                $customerTaxClassId,
                $effectiveBillingCountry,
                $resolvedLineItems
            );

            if ($taxDetails === null) {
                // No tax rule matched: customerClass + productClass + billingCountry
                // combination has no rule in Magento. This is a configuration gap.
                $response = $this->buildErrorResponse(
                    self::ERR_RULE_NOT_FOUND,
                    "TAX_RULE_NOT_FOUND — No tax rule configured for: "
                    . "customerClass='{$resolvedCustomerClass}', "
                    . "billingCountry='{$effectiveBillingCountry}', "
                    . "legalEntity='{$legalEntity}'. "
                    . "Please contact the tax team.",
                    400
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $taxStatus, $gstDeclarationAccepted, $outsideSg, $vatNumber, $billingSystem
                );
                return $response;
            }

            // -----------------------------------------------------------------
            // STEP 6 — Build the success response.
            //
            // tax_comment is fetched per line item from the matched Magento tax rule's
            // custom insead_tax_comment field. Items sharing the same product tax class
            // will naturally share the same comment. Results are cached per product
            // tax class ID to avoid redundant DB queries.
            // -----------------------------------------------------------------
            $response = $this->buildSuccessResponse(
                $taxDetails,
                $currency,
                $customerTaxClassId,
                $effectiveBillingCountry,
                $resolvedLineItems
            );

            $this->logCalculation(
                $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                $subtotal, $currency, $lineItemArrays, $response,
                $taxStatus, $gstDeclarationAccepted, $outsideSg, $vatNumber, $billingSystem
            );

            return $response;

        } catch (\Exception $e) {
            // Catch-all for unexpected errors (DB failures, DI issues, etc.).
            // Logs the full exception then returns a structured TAX_ENGINE_ERROR response
            // so the billing system always gets a parseable JSON, never a raw exception.
            $this->logger->error(
                'Insead_TaxApi: unexpected engine error: ' . $e->getMessage(),
                [
                    'exception'      => $e,
                    'legal_entity'   => $legalEntity,
                    'billing_country' => $billingCountry,
                ]
            );

            $response = $this->buildErrorResponse(
                self::ERR_ENGINE_ERROR,
                'TAX_ENGINE_ERROR — An unexpected error occurred. Please contact the tax team. '
                . 'Ref: ' . $e->getMessage(),
                500
            );

            $this->logCalculation(
                $legalEntity,
                $customerType,
                $billingCountry,
                $programmeDeliveryLocation ?? 'NA',
                $subtotal,
                $currency,
                $lineItemArrays,
                $response,
                $taxStatus,
                $gstDeclarationAccepted,
                $outsideSg,
                $vatNumber,
                $billingSystem
            );

            return $response;
        }
    }

    // =========================================================================
    // DTO normalisation
    // =========================================================================

    /**
     * Convert LineItemInterface DTOs or raw arrays into normalised plain arrays.
     *
     * Magento's WebAPI layer injects LineItemInterface objects when the request
     * comes through the REST endpoint. The admin test form sends plain arrays.
     * This method handles both cases uniformly so all downstream logic works
     * with a single consistent array structure.
     *
     * product_family and delivery_mode are uppercased here so all comparisons
     * throughout the service are case-insensitive without repeated strtoupper() calls.
     *
     * @param  LineItemInterface[]|array[] $lineItems
     * @return array[]
     */
    private function normaliseLineItems(array $lineItems): array
    {
        return array_map(static function ($item): array {
            if ($item instanceof LineItemInterface) {
                $arr = [
                    'product_family' => $item->getProductFamily(),
                    'delivery_mode'  => $item->getDeliveryMode(),
                    'duration'       => $item->getDuration(),
                    'price'          => $item->getPrice(),
                    'qty'            => $item->getQty(),
                    'name'           => $item->getName() ?? '',
                ];
            } else {
                $arr             = (array) $item;
                $arr['duration'] = isset($arr['duration']) ? (int) $arr['duration'] : null;
            }

            // Normalise string fields to uppercase for case-insensitive comparisons.
            $arr['product_family'] = strtoupper(trim($arr['product_family'] ?? ''));
            $arr['delivery_mode']  = strtoupper(trim($arr['delivery_mode']  ?? ''));

            return $arr;
        }, $lineItems);
    }

    // =========================================================================
    // SKU construction
    // =========================================================================

    /**
     * Build the Magento generic product SKU from line item and request fields.
     *
     * Pattern:
     *   {legalEntity}_{deliveryModePrefix}_{productFamily}_{durationSuffix}_{pdl}
     *
     * The product with this SKU must exist in Magento as a simple product with
     * a product tax class assigned. It carries no price, stock, or visibility.
     * Its sole purpose is to map this combination to a product tax class.
     *
     * @param string   $legalEntity               e.g. SGP, FBL, UAE, USA
     * @param string   $productFamily             e.g. OOP, OEP, CSP (already uppercased)
     * @param string   $deliveryMode              e.g. ONLINE, F2F (already uppercased)
     * @param int|null $duration                  Programme duration in days (null = unknown)
     * @param string   $programmeDeliveryLocation e.g. SGP, FBL, NA
     * @return string  e.g. SGP_OL_OOP_NA_NA, FBL_IP_OEP_GT1W_FBL
     */
    private function buildSku(
        string $legalEntity,
        string $productFamily,
        string $deliveryMode,
        ?string $duration,
        string $programmeDeliveryLocation
    ): string {
        // Determine delivery mode prefix: OL for online/virtual, IP for in-person.
        $prefix = in_array($deliveryMode, self::DELIVERY_MODE_OL, true) ? 'OL' : 'IP';

        // Determine duration suffix for OEP/CSP F2F only.
        $durationSuffix = $this->resolveDurationSuffix($productFamily, $deliveryMode, $duration);

        return $legalEntity
            . '_' . $prefix
            . '_' . $productFamily
            . '_' . $durationSuffix
            . '_' . $programmeDeliveryLocation;
    }

    /**
     * Resolve the duration suffix component of the SKU.
     *
     * Duration is only meaningful for OEP and CSP with F2F delivery.
     * All other combinations always get NA regardless of the duration value.
     *
     * Allowed values: short -> SHORT, long -> LONG.
     * Missing, null, or invalid defaults to NA.
     *
     * @param string      $productFamily  Already uppercased
     * @param string      $deliveryMode   Already uppercased
     * @param string|null $duration       Programme duration (short, long)
     * @return string  SHORT | LONG | NA
     */
    private function resolveDurationSuffix(
        string $productFamily,
        string $deliveryMode,
        ?string $duration
    ): string {
        // Only OEP and CSP with F2F delivery use duration-based suffixes.
        if (in_array($productFamily, self::DURATION_FAMILIES, true) && $deliveryMode === 'F2F') {
            $durationLower = $duration !== null ? strtolower(trim($duration)) : null;

            if ($durationLower === 'short') {
                return 'SHORT';
            }
            if ($durationLower === 'long') {
                return 'LONG';
            }
        }

        // All other programme/delivery combinations use NA (not applicable).
        return 'NA';
    }

    // =========================================================================
    // Customer tax class resolution
    // =========================================================================

    /**
     * Resolve the Magento customer tax class name from the request flags.
     *
     * B2B priority order (first match wins):
     *   1. taxStatus = "Tax Exempt" → B2B_EXEMPT
     *      Formal tax exemption — overrides everything. All entities.
     *   2. legalEntity=SGP AND gstDeclarationAccepted=true → B2B_GST_EXEMPT
     *      Customer provided a GST declaration letter. Any programme type. SGP only.
     *   3. Default → B2B
     *      All other B2B customers — standard taxable.
     *
     * B2C:
     *   Always → B2C. taxStatus and gstDeclarationAccepted have no effect.
     *   The outside_sg billing country override is handled separately.
     *
     * @param string      $customerType           B2B or B2C (uppercased)
     * @param string|null $taxStatus              Tax Exempt / Tax Registered / Not Tax Registered
     * @param bool|null   $gstDeclarationAccepted true = GST letter provided
     * @param string      $legalEntity            SGP / FBL / UAE / USA (uppercased)
     * @return string  Magento customer tax class name
     */
    private function resolveCustomerTaxClassName(
        string $customerType,
        ?string $taxStatus,
        ?bool $gstDeclarationAccepted,
        string $legalEntity
    ): string {
        // B2C customers always resolve to B2C — no further checks needed.
        if ($customerType === 'B2C') {
            return self::CLASS_B2C;
        }

        // Priority 1: Tax Exempt status wins over all other flags, all entities.
        // Use case: government bodies, embassies with formal exemption certificate.
        if ($taxStatus === self::TAX_STATUS_EXEMPT) {
            return self::CLASS_B2B_EXEMPT;
        }

        // Priority 2: SGP GST declaration letter provided.
        // The customer signed INSEAD's GST declaration form → 0% GST.
        // Applies to any programme type, not just OOP.
        // Only relevant for SGP entity — ignored for FBL, UAE, USA.
        if ($legalEntity === 'SGP' && $gstDeclarationAccepted === true) {
            return self::CLASS_B2B_GST_EXEMPT;
        }

        // Priority 3: Default B2B — all other cases.
        // Includes Tax Registered, Not Tax Registered, and null taxStatus.
        return self::CLASS_B2B;
    }

    /**
     * Look up the numeric customer tax class ID from Magento by class name.
     *
     * Uses SearchCriteriaBuilderFactory (not a shared SearchCriteriaBuilder instance)
     * to avoid filter accumulation across multiple calls — a known Magento 2 gotcha.
     * Results are cached per class name for the lifetime of this request.
     *
     * Returns null if the class does not exist → triggers TAX_CLASS_NOT_FOUND error.
     *
     * @param  string   $className  e.g. B2B, B2B_EXEMPT, B2B_GST_EXEMPT, B2C
     * @return int|null Magento tax class ID, or null if not found
     */
    private function getCustomerTaxClassId(string $className): ?int
    {
        // Return cached result if we already looked this up in this request.
        if (array_key_exists($className, $this->customerTaxClassCache)) {
            return $this->customerTaxClassCache[$className];
        }

        try {
            // Fresh SearchCriteriaBuilder from factory to avoid filter accumulation.
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('class_name', $className, 'eq')
                ->addFilter('class_type', self::TAX_CLASS_TYPE_CUSTOMER, 'eq')
                ->create();

            $classes = $this->taxClassRepository->getList($searchCriteria)->getItems();
            $classId = !empty($classes) ? (int) reset($classes)->getClassId() : null;
        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: failed to look up customer tax class: ' . $e->getMessage(),
                ['class_name' => $className]
            );
            $classId = null;
        }

        return $this->customerTaxClassCache[$className] = $classId;
    }

    /**
     * Look up the product tax class ID by loading the generic Magento product by SKU.
     *
     * Generic products are simple products with:
     *   - SKU matching the constructed pattern
     *   - A product tax class assigned (not "None" / ID 0)
     *   - No price, stock, or visibility (irrelevant for this use case)
     *
     * Tax class ID 0 means "None" in Magento — treated as not configured.
     * Results are cached per SKU for the lifetime of this request.
     *
     * Returns null if the product is missing or has no tax class → TAX_SKU_NOT_FOUND.
     *
     * @param  string   $sku  Constructed SKU e.g. SGP_OL_OOP_NA_NA
     * @return int|null Magento product tax class ID, or null if not found/unconfigured
     */
    private function getProductTaxClassIdBySku(string $sku): ?int
    {
        // Return cached result if we already looked this up in this request.
        if (array_key_exists($sku, $this->productTaxClassCache)) {
            return $this->productTaxClassCache[$sku];
        }

        try {
            $product    = $this->productRepository->get($sku);
            $taxClassId = (int) $product->getTaxClassId();

            if ($taxClassId === 0) {
                // Product exists but has no product tax class assigned.
                // Admin must assign a tax class at Catalog → Products → edit → Tax Class.
                $this->logger->warning(
                    "Insead_TaxApi: Product '{$sku}' exists but has no tax class assigned (Tax Class = None).",
                    ['sku' => $sku]
                );
                $classId = null;
            } else {
                $classId = $taxClassId;
            }
        } catch (NoSuchEntityException) {
            // Product with this SKU does not exist in Magento.
            // Admin must create a generic product with this exact SKU.
            $this->logger->warning(
                "Insead_TaxApi: Generic product SKU '{$sku}' not found in Magento.",
                ['sku' => $sku]
            );
            $classId = null;
        } catch (\Exception $e) {
            $this->logger->error(
                "Insead_TaxApi: Error loading product SKU '{$sku}': " . $e->getMessage()
            );
            $classId = null;
        }

        return $this->productTaxClassCache[$sku] = $classId;
    }

    // =========================================================================
    // Billing country resolution
    // =========================================================================

    /**
     * Resolve the effective billing country to pass to the Magento tax engine.
     *
     * In almost all cases this returns the billingCountry from the request unchanged.
     *
     * SGP B2C OOP exception (outside_sg rule):
     *   When a B2C customer will be physically present in Singapore during an OOP
     *   programme, Singapore GST applies regardless of their billing address country.
     *   The outside_sg=false flag triggers an internal override of billingCountry to SG.
     *
     * Conditions for override (all must be true):
     *   - legalEntity = SGP
     *   - customerType = B2C
     *   - outsideSg = false  (null is treated as true — use actual billing country)
     *   - At least one line item has product_family = OOP
     *
     * This rule only applies to OOP — OEP/CSP delivered at SGP campus are already
     * handled correctly via the programmeDeliveryLocation in the SKU.
     *
     * @param string      $legalEntity      Uppercased
     * @param string      $customerType     Uppercased
     * @param string      $billingCountry   ISO-2, uppercased
     * @param bool|null   $outsideSg        false = inside SG, true/null = outside SG
     * @param array[]     $lineItemArrays   Normalised line items
     * @return string  Effective billing country for the tax engine
     */
    private function resolveEffectiveBillingCountry(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        ?bool $outsideSg,
        array $lineItemArrays
    ): string {
        // Override only applies to SGP entity, B2C customers, with outsideSg explicitly false.
        // null outsideSg = customer did not declare → treat as outside SG (safe default).
        if ($legalEntity === 'SGP' && $customerType === 'B2C' && $outsideSg === false) {
            // Check if any line item is OOP — the rule only applies to OOP programmes.
            foreach ($lineItemArrays as $item) {
                if (($item['product_family'] ?? '') === 'OOP') {
                    $this->logger->info(
                        'Insead_TaxApi: outside_sg=false — billingCountry overridden to SG for tax engine.',
                        ['original_billing_country' => $billingCountry]
                    );
                    return 'SG';
                }
            }
        }

        // All other cases: use the billing country as sent in the request.
        return $billingCountry;
    }

    // =========================================================================
    // Magento tax engine
    // =========================================================================

    /**
     * Build a QuoteDetails object and pass it to Magento's native tax engine.
     *
     * QuoteDetails is the standard Magento input DTO for tax calculation.
     * It holds:
     *   - A virtual billing/shipping address with the effective country
     *   - The customer tax class key (by ID, not name — avoids redundant lookup)
     *   - One QuoteDetailsItem per line item, each with its product tax class key
     *
     * Using TaxClassKey::TYPE_ID avoids the engine doing its own name-to-ID
     * resolution internally, which would cause duplicate DB queries.
     *
     * isTaxIncluded=false: external billing systems always send ex-tax amounts.
     * storeId=null: Magento resolves the current store — tax rules are global.
     *
     * @param  int    $customerTaxClassId Resolved from STEP 2
     * @param  string $countryId          Effective billing country from STEP 4
     * @param  array  $resolvedLineItems  Items with _product_tax_class_id populated
     * @return \Magento\Tax\Api\Data\TaxDetailsInterface|null null = no rule matched
     */
    private function runTaxEngine(
        int $customerTaxClassId,
        string $countryId,
        array $resolvedLineItems
    ): ?\Magento\Tax\Api\Data\TaxDetailsInterface {
        try {
            $quoteDetails = $this->quoteDetailsFactory->create();

            // Attach a virtual billing and shipping address with just the country.
            // Postcode '*' matches wildcard tax rate zones in Magento.
            $address = $this->getVirtualAddress($countryId);
            $quoteDetails->setBillingAddress($address);
            $quoteDetails->setShippingAddress($address);

            // Set customer tax class by ID — direct lookup, no name resolution.
            $customerKey = $this->taxClassKeyFactory->create();
            $customerKey->setType(TaxClassKey::TYPE_ID)->setValue((string) $customerTaxClassId);
            $quoteDetails->setCustomerTaxClassKey($customerKey);

            // Build and attach one QuoteDetailsItem per line item.
            $quoteDetails->setItems($this->buildQuoteItems($resolvedLineItems));

            // Invoke the Magento tax engine.
            $taxDetails = $this->taxCalculation->calculateTax($quoteDetails);

            // Check if at least one item has applied taxes.
            // An empty appliedTaxes array on all items means no rule matched.
            if (!$this->hasTaxRuleApplied($taxDetails)) {
                $this->logger->warning(
                    'Insead_TaxApi: Tax engine returned no applied taxes — no rule matched.',
                    [
                        'customer_tax_class_id' => $customerTaxClassId,
                        'country'               => $countryId,
                    ]
                );
                return null;
            }

            return $taxDetails;

        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: Tax engine threw an exception: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }
    }

    /**
     * Build the array of QuoteDetailsItem objects for the tax engine.
     *
     * Each item carries:
     *   - code: "item_0", "item_1", etc. — used to match engine output back to input
     *   - type: "product" — standard Magento tax item type
     *   - quantity, unit price (ex-tax)
     *   - isTaxIncluded: false — prices are always sent exclusive of tax
     *   - product tax class key: by TYPE_ID for efficiency
     *
     * @param  array $resolvedLineItems Items with _sku and _product_tax_class_id
     * @return \Magento\Tax\Api\Data\QuoteDetailsItemInterface[]
     */
    private function buildQuoteItems(array $resolvedLineItems): array
    {
        $items = [];

        foreach ($resolvedLineItems as $index => $item) {
            $quoteItem = $this->quoteDetailsItemFactory->create();
            $quoteItem
                ->setCode('item_' . $index)
                ->setType('product')
                ->setQuantity((float) $item['qty'])
                ->setUnitPrice((float) $item['price'])
                ->setIsTaxIncluded(false)
                ->setName($item['name'] ?: $item['_sku']);

            // Use TYPE_ID to pass the product tax class directly without name lookup.
            $productKey = $this->taxClassKeyFactory->create();
            $productKey->setType(TaxClassKey::TYPE_ID)
                ->setValue((string) $item['_product_tax_class_id']);
            $quoteItem->setTaxClassKey($productKey);

            $items[] = $quoteItem;
        }

        return $items;
    }

    /**
     * Determine whether the tax engine actually applied a tax rule.
     *
     * Magento returns a TaxDetailsInterface with zero-rate items when no rule matches.
     * We detect this by checking if at least one item has a non-empty appliedTaxes array.
     * An item with appliedTaxes=[] means the engine found no matching rule for it.
     *
     * @param  \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails
     * @return bool true if at least one item has an applied tax
     */
    private function hasTaxRuleApplied(
        \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails
    ): bool {
        foreach ($taxDetails->getItems() as $item) {
            if (!empty($item->getAppliedTaxes())) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Virtual address
    // =========================================================================

    /**
     * Create or return a cached virtual billing address for the given country.
     *
     * The Magento tax engine requires a customer address object. Since we only
     * care about the country (not city, street, or region), we use a minimal
     * virtual address. Postcode '*' matches wildcard tax rate zones.
     *
     * Results are cached by country ID to avoid creating duplicate objects
     * within the same request (e.g. when all items have the same country).
     *
     * @param  string $countryId ISO-2 e.g. SG, FR, DE
     * @return \Magento\Customer\Api\Data\AddressInterface
     */
    private function getVirtualAddress(string $countryId): \Magento\Customer\Api\Data\AddressInterface
    {
        if (!isset($this->virtualAddressCache[$countryId])) {
            $address = $this->addressFactory->create();
            $address->setCountryId($countryId);
            $address->setPostcode('*');
            $address->setCity('Virtual City');
            $address->setStreet(['Virtual Street']);

            // Region must be set (even as null) to avoid Magento validation errors.
            $region = $this->regionFactory->create();
            $region->setRegionId(null);
            $address->setRegion($region);

            $this->virtualAddressCache[$countryId] = $address;
        }

        return $this->virtualAddressCache[$countryId];
    }

    // =========================================================================
    // INSEAD tax comment lookup
    // =========================================================================

    /**
     * Retrieve the custom insead_tax_comment field from the matched Magento tax rule.
     *
     * Each Magento tax rule has a custom insead_tax_comment field added by this module.
     * The comment is used by the billing system (Salesforce) for invoice display.
     * Examples: "GST @9%", "GST @0% — GST Declaration Accepted",
     *           "Reverse-charge: Customer to pay the VAT", "Local EU VAT @21% — to pay via OSS portal"
     *
     * Lookup uses 'finset' condition because Magento stores tax rule class associations
     * as comma-separated IDs in the tax_calculation table — not as individual rows.
     *
     * Returns null if the comment field is empty or the rule cannot be found.
     * A null comment in the response means the admin forgot to fill in the field.
     *
     * @param  int    $customerTaxClassId
     * @param  int    $productTaxClassId
     * @param  string $countryId           Not currently used in the query but kept for
     *                                      future country-specific comment overrides
     * @return string|null
     */
    private function getInseadTaxComment(
        int $customerTaxClassId,
        int $productTaxClassId,
        string $countryId
    ): ?string {
        try {
            // Fresh SearchCriteriaBuilder — avoids filter accumulation.
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('customer_tax_class_ids', $customerTaxClassId, 'finset')
                ->addFilter('product_tax_class_ids', $productTaxClassId, 'finset')
                ->create();

            $rules = $this->taxRuleRepository->getList($searchCriteria)->getItems();

            // Return the first non-empty comment found.
            foreach ($rules as $rule) {
                $comment = $rule->getData('insead_tax_comment');
                if (!empty($comment)) {
                    return (string) $comment;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: Failed to fetch insead_tax_comment from tax rule: ' . $e->getMessage()
            );
        }

        return null;
    }

    /**
     * Retrieve the custom fields (insead_tax_comment, fusion_tax_code, tax_article) from the matched Magento tax rule.
     */
    private function getCustomTaxRuleData(
        int $customerTaxClassId,
        int $productTaxClassId,
        string $countryId
    ): array {
        $data = ['comment' => null, 'fusion_code' => null, 'tax_article' => null];

        try {
            if (!isset($this->countryRateIdsCache[$countryId])) {
                $rateSearchCriteria = $this->searchCriteriaBuilderFactory->create()
                    ->addFilter('tax_country_id', $countryId, 'eq')
                    ->create();
                
                $rates = $this->taxRateRepository->getList($rateSearchCriteria)->getItems();
                
                $this->countryRateIdsCache[$countryId] = array_map(
                    static fn($rate) => (int) $rate->getId(),
                    $rates
                );
            }
            
            $countryRateIds = $this->countryRateIdsCache[$countryId];

            if (empty($countryRateIds)) {
                return $data;
            }

            $ruleSearchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('customer_tax_class_ids', $customerTaxClassId, 'finset')
                ->addFilter('product_tax_class_ids', $productTaxClassId, 'finset')
                ->create();

            $rules = $this->taxRuleRepository->getList($ruleSearchCriteria)->getItems();

            foreach ($rules as $rule) {
                $ruleRateIds = array_map('intval', $rule->getTaxRateIds() ?? []);

                if (!empty(array_intersect($ruleRateIds, $countryRateIds))) {
                    $comment = $rule->getData('insead_tax_comment');
                    $fusionCode = $rule->getData('fusion_tax_code');
                    $taxArticle = $rule->getData('tax_article');

                    $data['comment'] = !empty($comment) ? (string) $comment : null;
                    $data['fusion_code'] = !empty($fusionCode) ? (string) $fusionCode : null;
                    $data['tax_article'] = !empty($taxArticle) ? (string) $taxArticle : null;

                    return $data;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: Failed to fetch custom fields from tax rule: ' . $e->getMessage()
            );
        }

        return $data;
    }

    // =========================================================================
    // Input validation
    // =========================================================================

    /**
     * Validate all top-level and per-line-item inputs.
     *
     * All validations run on already-normalised (uppercase) values.
     * Returns on the first failure — does not accumulate all errors.
     *
     * @param  string  $legalEntity
     * @param  string  $customerType
     * @param  string  $billingCountry
     * @param  float   $subtotal
     * @param  string  $currency
     * @param  array[] $lineItemArrays   Normalised plain arrays
     * @param  string  $programmeDeliveryLocation
     * @return array{valid: bool, message?: string}
     */
    private function validateInput(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        float $subtotal,
        string $currency,
        array $lineItemArrays,
        string $programmeDeliveryLocation
    ): array {
        // Legal entity must be one of the four configured INSEAD entities.
        if (!in_array($legalEntity, self::LEGAL_ENTITIES, true)) {
            return [
                'valid'   => false,
                'message' => "Invalid legalEntity '{$legalEntity}'. "
                    . 'Must be one of: ' . implode(', ', self::LEGAL_ENTITIES),
            ];
        }

        // Customer type drives the entire tax class resolution chain.
        if (!in_array($customerType, ['B2B', 'B2C'], true)) {
            return ['valid' => false, 'message' => 'customerType must be B2B or B2C'];
        }

        // Billing country must be a valid 2-character ISO code.
        if (empty($billingCountry) || strlen($billingCountry) !== 2) {
            return ['valid' => false, 'message' => 'billingCountry must be a valid ISO-2 code (e.g. SG, FR, DE)'];
        }

        // Subtotal must be non-negative.
        if ($subtotal < 0) {
            return ['valid' => false, 'message' => 'subtotal must be zero or a positive number'];
        }

        // Currency must be a valid 3-character ISO code.
        if (empty($currency) || strlen($currency) !== 3) {
            return ['valid' => false, 'message' => 'currency must be a valid ISO-3 code (e.g. SGD, EUR, USD)'];
        }

        // At least one line item is required.
        if (empty($lineItemArrays)) {
            return ['valid' => false, 'message' => 'lineItems must contain at least one item'];
        }

        // Programme delivery location is required (use NA for online programmes).
        if (empty($programmeDeliveryLocation)) {
            return [
                'valid'   => false,
                'message' => 'programmeDeliveryLocation is required. Use NA for online programmes.',
            ];
        }

        // Validate each line item individually.
        foreach ($lineItemArrays as $index => $item) {
            if (empty($item['product_family'])) {
                return [
                    'valid'   => false,
                    'message' => "Line item {$index}: 'product_family' is required",
                ];
            }

            if (!in_array($item['product_family'], self::PRODUCT_FAMILIES, true)) {
                return [
                    'valid'   => false,
                    'message' => "Line item {$index}: invalid product_family '{$item['product_family']}'. "
                        . 'Must be one of: ' . implode(', ', self::PRODUCT_FAMILIES),
                ];
            }

            if (empty($item['delivery_mode'])) {
                return [
                    'valid'   => false,
                    'message' => "Line item {$index}: 'delivery_mode' is required",
                ];
            }

            if (!in_array($item['delivery_mode'], self::DELIVERY_MODES, true)) {
                return [
                    'valid'   => false,
                    'message' => "Line item {$index}: invalid delivery_mode '{$item['delivery_mode']}'. "
                        . 'Must be one of: Online, F2F, Live Virtual',
                ];
            }

            if (!isset($item['price'], $item['qty'])) {
                return [
                    'valid'   => false,
                    'message' => "Line item {$index}: 'price' and 'qty' are required",
                ];
            }

            if ((float) $item['price'] < 0) {
                return ['valid' => false, 'message' => "Line item {$index}: price must be >= 0"];
            }

            if ((float) $item['qty'] <= 0) {
                return ['valid' => false, 'message' => "Line item {$index}: qty must be > 0"];
            }
        }

        return ['valid' => true];
    }

    /**
     * Format the tax comment for display.
     *
     * For EU B2C rules the raw comment stored on the tax rule is "Local EU VAT @".
     * This method appends the actual tax rate and the OSS portal instruction:
     *   "Local EU VAT @X% - to pay via OSS portal"
     *
     * All other comments are returned as-is.
     *
     * @param  string|null $rawComment Raw insead_tax_comment from the matched tax rule.
     * @param  float       $taxRate    Resolved tax rate percentage for this line item.
     * @return string|null
     */
    private function formatTaxComment(?string $rawComment, float $taxRate): ?string
    {
        if ($rawComment === null) {
            return null;
        }

        if (str_starts_with($rawComment, self::EU_OSS_COMMENT_PREFIX)) {
            return self::EU_OSS_COMMENT_PREFIX . $taxRate . '% - to pay via OSS portal';
        }

        return $rawComment;
    }

    // =========================================================================
    // Response builders
    // =========================================================================

    /**
     * Build the success response from Magento's TaxDetailsInterface.
     *
     * Processes each TaxDetailsItem returned by the engine:
     *   - Calculates item rate (sum of all applied tax percentages)
     *   - Retrieves the INSEAD tax comment for this item's product tax class
     *     (cached per product tax class ID to avoid duplicate DB queries)
     *   - Builds a LineItemResult DTO with full breakdown
     *
     * The top-level tax_rate is the average across all line items.
     * tax_comment lives only on each line item — not at the top level.
     *
     * @param  \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails
     * @param  string $currency
     * @param  int    $customerTaxClassId  For per-item tax comment lookup
     * @param  string $countryId           Effective billing country
     * @param  array  $resolvedLineItems   Items with _sku, _product_tax_class_id
     * @return TaxResponseInterface
     */
    private function buildSuccessResponse(
        \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails,
        string $currency,
        int $customerTaxClassId,
        string $countryId,
        array $resolvedLineItems = []
    ): TaxResponseInterface {
        $engineItems = $taxDetails->getItems();
        $totalRate   = 0.0;
        $count       = count($engineItems);
        $lineResults = [];

        // Index original line items by their code (item_0, item_1, ...)
        // so we can match engine output back to input for the full per-item breakdown.
        $originalByCode = [];
        foreach ($resolvedLineItems as $index => $item) {
            $originalByCode['item_' . $index] = $item;
        }

        // Cache tax comments per product tax class ID.
        // Avoids a separate DB query for each item when multiple items share
        // the same product tax class (e.g. two OOP items in one request).
        $ruleDataCache = [];

        foreach ($engineItems as $engineItem) {
            // Sum all applied tax percentages for this item.
            // Usually this is a single rate, but Magento supports stacked taxes.
            $itemRate     = 0.0;
            $appliedTaxes = $engineItem->getAppliedTaxes() ?? [];
            foreach ($appliedTaxes as $appliedTax) {
                $itemRate += (float) $appliedTax->getPercent();
            }
            $totalRate += $itemRate;

            $code     = $engineItem->getCode();
            $original = $originalByCode[$code] ?? [];

            // Calculate row totals rounded to 2 decimal places.
            $rowTotal     = round((float) $engineItem->getRowTotal(), 2);
            $rowTax       = round((float) $engineItem->getRowTax(), 2);
            $rowTotalIncl = round($rowTotal + $rowTax, 2);

            // Fetch tax comment for this item's product tax class.
            // The comment comes from the custom insead_tax_comment field on the matched rule.
            $productTaxClassId = (int) ($original['_product_tax_class_id'] ?? 0);
            if (!array_key_exists($productTaxClassId, $ruleDataCache)) {
                $ruleDataCache[$productTaxClassId] = $this->getCustomTaxRuleData(
                    $customerTaxClassId,
                    $productTaxClassId,
                    $countryId
                );
            }

            $customData = $ruleDataCache[$productTaxClassId];

            $lineResult = $this->lineItemResultFactory->create();
            $lineResult
                ->setCode($code)
                ->setName($original['name'] ?? null)
                ->setTaxProductCode(
                    ($original['product_family'] ?? '') . '_' . ($original['delivery_mode'] ?? '')
                )
                ->setPrice(round((float) ($original['price'] ?? 0), 2))
                ->setQty((float) ($original['qty'] ?? 1))
                ->setRowTotal($rowTotal)
                ->setTaxRate(round($itemRate, 2))
                ->setTaxAmount($rowTax)
                ->setRowTotalInclTax($rowTotalIncl)
                ->setTaxComment($this->formatTaxComment($customData['comment'], $itemRate))
                ->setFusionTaxCode($customData['fusion_code'])
                ->setTaxArticle($customData['tax_article']);

            $lineResults[] = $lineResult;
        }

        $subtotal  = (float) $taxDetails->getSubtotal();
        $taxAmount = (float) $taxDetails->getTaxAmount();

        return $this->taxResponseFactory->create()->setData([
            'status'        => self::STATUS_SUCCESS,
            'response_code' => 200,
            'tax_rate'      => round($count > 0 ? $totalRate / $count : 0.0, 2),
            'subtotal'      => round($subtotal, 2),
            'tax_amount'    => round($taxAmount, 2),
            'grand_total'   => round($subtotal + $taxAmount, 2),
            'currency'      => $currency,
            'line_items'    => $lineResults,
        ]);
    }

    /**
     * Build a structured error response.
     *
     * All failure scenarios return this format so the billing system always gets
     * a parseable JSON response, never a raw PHP exception or empty body.
     *
     * The message field always starts with the error code constant so Salesforce
     * can parse it programmatically without relying on the human-readable text.
     *
     * Error codes:
     *   TAX_INVALID_INPUT   — Bad request payload (400)
     *   TAX_SKU_NOT_FOUND   — Missing Magento generic product (400)
     *   TAX_CLASS_NOT_FOUND — Missing Magento customer tax class (400)
     *   TAX_RULE_NOT_FOUND  — No matching tax rule configured (400)
     *   TAX_ENGINE_ERROR    — Unexpected internal error (500)
     *
     * @param  string $errorCode     One of the ERR_* constants
     * @param  string $message       Human-readable detail appended after the code
     * @param  int    $responseCode  HTTP-style response code (400 or 500)
     * @return TaxResponseInterface
     */
    private function buildErrorResponse(
        string $errorCode,
        string $message,
        int $responseCode = 400
    ): TaxResponseInterface {
        return $this->taxResponseFactory->create()->setData([
            'status'        => self::STATUS_ERROR,
            'response_code' => $responseCode,
            'message'       => $errorCode . ' — ' . $message,
        ]);
    }

    // =========================================================================
    // Audit logging
    // =========================================================================

    /**
     * Persist a full audit log entry for every API call regardless of outcome.
     *
     * Every request — success, error, or internal failure — is logged to
     * insead_taxapi_calculation_log. This provides a complete audit trail
     * for tax compliance and debugging.
     *
     * Stored per entry:
     *   - Full request parameters (all top-level fields + line items as JSON)
     *   - Full response JSON
     *   - Resolved tax rate, amounts, status
     *
     * Logging failures are caught and logged to the system log only —
     * they must never cause the API to return an error to the caller.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    private function logCalculation(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        string $programmeDeliveryLocation,
        float $subtotal,
        string $currency,
        array $lineItemArrays,
        TaxResponseInterface $response,
        ?string $taxStatus = null,
        ?bool $gstDeclarationAccepted = null,
        ?bool $outsideSg = null,
        ?string $vatNumber = null,
        ?string $billingSystem = null
    ): void {
        try {
            $responseData = $response->getData();

            // Collect unique product family codes for the product_class column.
            // This gives a quick overview in the admin grid without parsing the JSON.
            $productFamilies = array_unique(
                array_filter(array_column($lineItemArrays, 'product_family'))
            );

            $log = $this->taxLogFactory->create();
            $log->setData([
                'legal_entity'                => $legalEntity,
                'customer_type'               => $customerType,
                'tax_status'                  => $taxStatus,
                'gst_declaration_accepted'    => $gstDeclarationAccepted !== null
                                                  ? (int) $gstDeclarationAccepted : null,
                'outside_sg'                  => $outsideSg !== null
                                                  ? (int) $outsideSg : null,
                'product_class'               => implode(', ', $productFamilies),
                'billing_country'             => $billingCountry,
                'programme_delivery_location' => $programmeDeliveryLocation,
                'vat_number'                  => $vatNumber,
                'subtotal'                    => $subtotal,
                'currency'                    => $currency,
                'billing_system'              => $billingSystem,

                // Full request JSON — used for debugging and re-running calculations.
                'line_items'   => json_encode($lineItemArrays, JSON_THROW_ON_ERROR),
                'request_data' => json_encode([
                    'legal_entity'                => $legalEntity,
                    'customer_type'               => $customerType,
                    'tax_status'                  => $taxStatus,
                    'gst_declaration_accepted'    => $gstDeclarationAccepted,
                    'outside_sg'                  => $outsideSg,
                    'billing_country'             => $billingCountry,
                    'programme_delivery_location' => $programmeDeliveryLocation,
                    'vat_number'                  => $vatNumber,
                    'subtotal'                    => $subtotal,
                    'currency'                    => $currency,
                    'billing_system'              => $billingSystem,
                    'line_items'                  => $lineItemArrays,
                ], JSON_THROW_ON_ERROR),

                // Full response JSON — used for audit trail and debugging.
                'response_data' => json_encode($responseData, JSON_THROW_ON_ERROR),

                // Flattened fields for admin grid filtering without parsing JSON.
                'status'      => $responseData['status']     ?? 'unknown',
                'tax_rate'    => $responseData['tax_rate']   ?? null,
                'tax_amount'  => $responseData['tax_amount'] ?? null,
                'grand_total' => $responseData['grand_total'] ?? null,

                // tax_comment now lives per line item — stored as null at top level.
                'tax_comment' => null,
            ]);

            $log->save();

        } catch (\Exception $e) {
            // Logging must never break the API response.
            // Log the failure to system.log and continue.
            $this->logger->error(
                'Insead_TaxApi: Failed to write audit log: ' . $e->getMessage(),
                ['exception' => $e, 'legal_entity' => $legalEntity]
            );
        }
    }
}