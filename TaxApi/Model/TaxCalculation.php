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

/**
 * Tax calculation service — Magento 2.4.8 compliant implementation.
 *
 * Magento acts as a pure tax engine. The product catalogue lives in external systems.
 *
 * === THREE TAX ENGINE INPUTS ===
 *
 * 1. PRODUCT TAX CLASS
 *    SKU = {legalEntity}_{tax_product_code}_{programmeDeliveryLocation}
 *    e.g. SGP_OL_OOP_NA_NA, FBL_IP_OEP_SHORT_FBL
 *    ProductRepository::get(SKU) -> getTaxClassId()
 *    Error 400 if SKU not found or tax class = 0 (None)
 *
 * 2. CUSTOMER TAX CLASS
 *    Resolved from customerType + isValidVat + gstExempt (all optional)
 *    B2B                          -> B2B
 *    B2B + isValidVat             -> B2B_VAT
 *    B2B + gstExempt              -> B2B_GST_EXEMPT
 *    B2B + isValidVat + gstExempt -> B2B_VAT_GST_EXEMPT
 *    Same pattern for B2C.
 *    isTaxRegistered captured and stored for future rule differentiation.
 *
 * 3. BILLING COUNTRY (effective)
 *    Default : billingCountry as supplied
 *    Override: legalEntity=SGP AND participantCountry=SG AND programmeType=OOP -> use SG
 *
 * === FALLBACK RATES (when no Magento rule matches) ===
 *    FBL 20%   SGP 9%   UAE 5%   USA 0%
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxCalculation implements TaxCalculationInterface
{
    private const LEGAL_ENTITIES = ['FBL', 'SGP', 'UAE', 'USA'];

    /** @var array<string, float> */
    private const FALLBACK_RATES = [
        'FBL' => 20.0,
        'SGP' =>  9.0,
        'UAE' =>  5.0,
        'USA' =>  0.0,
    ];

    private const RESPONSE_SUCCESS = 'success';
    private const RESPONSE_WARNING = 'warning';
    private const RESPONSE_ERROR   = 'error';

    private const TAX_CLASS_TYPE_CUSTOMER = 'CUSTOMER';

    /** @var array<string, int|null> */
    private array $customerTaxClassCache = [];

    /** @var array<string, int|null> */
    private array $productTaxClassCache = [];

    /** @var array<string, \Magento\Customer\Api\Data\AddressInterface> */
    private array $virtualAddressCache = [];

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly MagentoTaxCalculationInterface   $taxCalculation,
        private readonly QuoteDetailsInterfaceFactory     $quoteDetailsFactory,
        private readonly QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        private readonly TaxClassKeyInterfaceFactory      $taxClassKeyFactory,
        private readonly AddressInterfaceFactory          $addressFactory,
        private readonly RegionInterfaceFactory           $regionFactory,
        private readonly TaxClassRepositoryInterface      $taxClassRepository,
        private readonly TaxRuleRepositoryInterface       $taxRuleRepository,
        private readonly SearchCriteriaBuilderFactory     $searchCriteriaBuilderFactory,
        private readonly ProductRepositoryInterface       $productRepository,
        private readonly LoggerInterface                  $logger,
        private readonly TaxCalculationLogFactory         $taxLogFactory,
        private readonly TaxResponseFactory               $taxResponseFactory,
        private readonly LineItemResultFactory             $lineItemResultFactory
    ) {
    }

    /**
     * @inheritDoc
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
    ): TaxResponseInterface {
        $lineItemArrays = [];

        try {
            // Normalise all string inputs to uppercase so the API is case-insensitive.
            // External systems may send "fbl", "FBL", "Fbl" — all treated identically.
            $legalEntity               = strtoupper(trim($legalEntity));
            $customerType              = strtoupper(trim($customerType));
            $billingCountry            = strtoupper(trim($billingCountry));
            $currency                  = strtoupper(trim($currency));
            $programmeDeliveryLocation = strtoupper(trim($programmeDeliveryLocation));
            $participantCountry        = $participantCountry !== null
                                         ? strtoupper(trim($participantCountry))
                                         : null;
            $programmeType             = $programmeType !== null
                                         ? strtoupper(trim($programmeType))
                                         : null;

            $lineItemArrays = $this->normaliseLineItems($lineItems);

            // 1. Validate
            $validation = $this->validateInput(
                $legalEntity, $customerType, $billingCountry,
                $subtotal, $currency, $lineItemArrays,
                $programmeDeliveryLocation, $participantCountry
            );

            if (!$validation['valid']) {
                $response = $this->buildErrorResponse($validation['message']);
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
                );
                return $response;
            }

            // 2. Resolve customer tax class
            $resolvedCustomerClass = $this->resolveCustomerTaxClassName(
                $customerType, $isValidVat, $gstExempt
            );
            // isTaxRegistered is captured for audit and future rule use.
            // Currently does not affect class resolution — no separate rules yet.

            $customerTaxClassId = $this->getCustomerTaxClassId($resolvedCustomerClass);

            if ($customerTaxClassId === null) {
                $response = $this->buildWarningResponse(
                    "Customer tax class '{$resolvedCustomerClass}' not found in Magento.",
                    $legalEntity, $subtotal, $currency
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
                );
                return $response;
            }

            // 3. Resolve product tax class per line item via SKU lookup
            $resolvedLineItems = [];
            foreach ($lineItemArrays as $index => $item) {
                $sku = $this->buildSku(
                    $legalEntity,
                    $item['tax_product_code'],
                    $programmeDeliveryLocation
                );

                $productTaxClassId = $this->getProductTaxClassIdBySku($sku);

                if ($productTaxClassId === null) {
                    $response = $this->buildErrorResponse(
                        "Constructed SKU '{$sku}' (line item {$index}) does not match"
                        . " any Magento product. Create a generic product with SKU = '{$sku}'."
                    );
                    $this->logCalculation(
                        $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                        $subtotal, $currency, $lineItemArrays, $response,
                        $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
                    );
                    return $response;
                }

                $resolvedLineItems[] = array_merge($item, [
                    '_sku'                  => $sku,
                    '_product_tax_class_id' => $productTaxClassId,
                ]);
            }

            // 4. Resolve effective billing country (SGP override)
            $effectiveBillingCountry = $this->resolveEffectiveBillingCountry(
                $legalEntity, $billingCountry, $participantCountry, $programmeType
            );

            // 5. Run Magento tax engine
            // storeId=null: Magento resolves current store internally — identical result
            $taxDetails = $this->runTaxEngine(
                $customerTaxClassId, $effectiveBillingCountry, $resolvedLineItems
            );

            if ($taxDetails === null) {
                $response = $this->buildWarningResponse(
                    "No tax rule matched for class '{$resolvedCustomerClass}',"
                    . " country '{$effectiveBillingCountry}', entity '{$legalEntity}'.",
                    $legalEntity, $subtotal, $currency
                );
                $this->logCalculation(
                    $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                    $subtotal, $currency, $lineItemArrays, $response,
                    $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
                );
                return $response;
            }

            // 6. Fetch custom INSEAD tax comment from the matched rule
            $taxComment = $this->getInseadTaxComment(
                $customerTaxClassId,
                $resolvedLineItems[0]['_product_tax_class_id'],
                $effectiveBillingCountry
            );

            // 7. Build and log success response
            $response = $this->buildSuccessResponse($taxDetails, $currency, $taxComment, $resolvedLineItems);
            $this->logCalculation(
                $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation,
                $subtotal, $currency, $lineItemArrays, $response,
                $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
            );

            return $response;

        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: calculation error: ' . $e->getMessage(),
                ['exception' => $e, 'legal_entity' => $legalEntity, 'billing_country' => $billingCountry]
            );
            $response = $this->buildErrorResponse('Internal server error: ' . $e->getMessage());
            $this->logCalculation(
                $legalEntity, $customerType, $billingCountry, $programmeDeliveryLocation ?? 'NA',
                $subtotal, $currency, $lineItemArrays, $response,
                $isValidVat, $gstExempt, $isTaxRegistered, $participantCountry, $vatNumber, $billingSystem, $programmeType
            );
            return $response;
        }
    }

    // =========================================================================
    // DTO normalisation
    // =========================================================================

    /**
     * Convert LineItemInterface DTOs (injected by WebAPI layer) to plain arrays.
     * Also accepts plain arrays for backward-compatibility with admin test form.
     *
     * @param LineItemInterface[]|array[] $lineItems
     * @return array[]
     */
    private function normaliseLineItems(array $lineItems): array
    {
        return array_map(static function ($item): array {
            if ($item instanceof LineItemInterface) {
                $arr = [
                    'tax_product_code' => $item->getTaxProductCode(),
                    'price'            => $item->getPrice(),
                    'qty'              => $item->getQty(),
                    'name'             => $item->getName() ?? '',
                ];
            } else {
                $arr = (array) $item;
            }
            // Normalise tax_product_code to uppercase — API is case-insensitive
            if (!empty($arr['tax_product_code'])) {
                $arr['tax_product_code'] = strtoupper(trim($arr['tax_product_code']));
            }
            return $arr;
        }, $lineItems);
    }

    // =========================================================================
    // SKU and class resolution
    // =========================================================================

    /**
     * Build Magento product SKU.
     * Pattern: {legalEntity}_{tax_product_code}_{programmeDeliveryLocation}
     * Example: SGP_OL_OOP_NA_NA | FBL_IP_OEP_SHORT_FBL | UAE_OL_FOOD_NA_NA
     */
    private function buildSku(
        string $legalEntity,
        string $taxProductCode,
        string $programmeDeliveryLocation
    ): string {
        return strtoupper($legalEntity)
            . '_' . strtoupper($taxProductCode)
            . '_' . strtoupper($programmeDeliveryLocation);
    }

    /**
     * Resolve the Magento customer tax class name from three input flags.
     *
     * B2B                          -> B2B
     * B2B + isValidVat             -> B2B_VAT
     * B2B + gstExempt              -> B2B_GST_EXEMPT
     * B2B + isValidVat + gstExempt -> B2B_VAT_GST_EXEMPT
     * Same pattern for B2C.
     * Null values for bools treated as false.
     */
    private function resolveCustomerTaxClassName(
        string $customerType,
        ?bool $isValidVat,
        ?bool $gstExempt
    ): string {
        $base = strtoupper($customerType);

        if ($isValidVat === true && $gstExempt === true) {
            return $base . '_VAT_GST_EXEMPT';
        }
        if ($isValidVat === true) {
            return $base . '_VAT';
        }
        if ($gstExempt === true) {
            return $base . '_GST_EXEMPT';
        }

        return $base;
    }

    /**
     * Look up customer tax class ID by class name.
     *
     * Uses SearchCriteriaBuilderFactory (not a shared SearchCriteriaBuilder instance)
     * to prevent filter accumulation across multiple calls — a common Magento 2 bug.
     * Result is cached in-memory for the lifetime of this request.
     */
    private function getCustomerTaxClassId(string $className): ?int
    {
        if (array_key_exists($className, $this->customerTaxClassCache)) {
            return $this->customerTaxClassCache[$className];
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('class_name', $className, 'eq')
                ->addFilter('class_type', self::TAX_CLASS_TYPE_CUSTOMER, 'eq')
                ->create();

            $classes = $this->taxClassRepository->getList($searchCriteria)->getItems();
            $classId = !empty($classes) ? (int) reset($classes)->getClassId() : null;
        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: error resolving customer tax class: ' . $e->getMessage(),
                ['class_name' => $className]
            );
            $classId = null;
        }

        return $this->customerTaxClassCache[$className] = $classId;
    }

    /**
     * Resolve product tax class ID by loading the generic Magento product by SKU.
     *
     * Generic products are created solely as tax class carriers.
     * Price, stock, and visibility are irrelevant.
     * Tax class ID 0 ("None") is treated as not configured.
     */
    private function getProductTaxClassIdBySku(string $sku): ?int
    {
        if (array_key_exists($sku, $this->productTaxClassCache)) {
            return $this->productTaxClassCache[$sku];
        }

        try {
            $product    = $this->productRepository->get($sku);
            $taxClassId = (int) $product->getTaxClassId();

            if ($taxClassId === 0) {
                $this->logger->warning(
                    "Insead_TaxApi: Product '{$sku}' has no tax class assigned (Tax Class = None).",
                    ['sku' => $sku]
                );
                $classId = null;
            } else {
                $classId = $taxClassId;
            }
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(
                "Insead_TaxApi: Product SKU '{$sku}' not found in Magento.",
                ['sku' => $sku]
            );
            $classId = null;
        } catch (\Exception $e) {
            $this->logger->error(
                "Insead_TaxApi: error loading product SKU '{$sku}': " . $e->getMessage()
            );
            $classId = null;
        }

        return $this->productTaxClassCache[$sku] = $classId;
    }

    // =========================================================================
    // Billing country override
    // =========================================================================

    /**
     * Resolve effective billing country for the Magento tax engine.
     *
     * OOP Singapore physical presence rule:
     *   IF legalEntity = SGP AND participantCountry = SG AND programmeType = OOP
     *   -> override billingCountry to SG
     *
     * This override applies ONLY to OOP — online programmes where the customer is
     * physically present in Singapore. OEP/CSP delivered at Singapore campus are
     * handled correctly via programmeDeliveryLocation in the SKU — no override needed.
     */
    private function resolveEffectiveBillingCountry(
        string $legalEntity,
        string $billingCountry,
        ?string $participantCountry,
        ?string $programmeType
    ): string {
        if (
            $legalEntity === 'SGP'
            && $participantCountry !== null
            && strtoupper($participantCountry) === 'SG'
            && strtoupper($programmeType ?? '') === 'OOP'
        ) {
            $this->logger->info('Insead_TaxApi: OOP SGP override — billingCountry overridden to SG.', [
                'original_billing_country' => $billingCountry,
                'participant_country'      => $participantCountry,
                'programme_type'           => $programmeType,
            ]);
            return 'SG';
        }

        return $billingCountry;
    }

    // =========================================================================
    // Magento tax engine
    // =========================================================================

    /**
     * Build QuoteDetails and run through Magento's native tax engine.
     *
     * Correct Magento 2.4.8 tax engine usage:
     * - QuoteDetails holds customer tax class key (TYPE_ID) and billing address
     * - Each QuoteDetailsItem holds product tax class key (TYPE_ID)
     * - isTaxIncluded = false (external systems send ex-tax amounts)
     * - storeId=null: Magento internally resolves current store (rules are global)
     * - Using TYPE_ID avoids redundant class name lookups inside the engine
     *
     * @param int    $customerTaxClassId
     * @param string $countryId          Effective billing country
     * @param array  $resolvedLineItems  Items with _product_tax_class_id populated
     * @return \Magento\Tax\Api\Data\TaxDetailsInterface|null  null = no rule matched
     */
    private function runTaxEngine(
        int $customerTaxClassId,
        string $countryId,
        array $resolvedLineItems
    ): ?\Magento\Tax\Api\Data\TaxDetailsInterface {
        try {
            $quoteDetails = $this->quoteDetailsFactory->create();

            $address = $this->getVirtualAddress($countryId);
            $quoteDetails->setBillingAddress($address);
            $quoteDetails->setShippingAddress($address);

            $customerKey = $this->taxClassKeyFactory->create();
            $customerKey->setType(TaxClassKey::TYPE_ID)
                ->setValue((string) $customerTaxClassId);
            $quoteDetails->setCustomerTaxClassKey($customerKey);

            $quoteDetails->setItems($this->buildQuoteItems($resolvedLineItems));

            $taxDetails = $this->taxCalculation->calculateTax($quoteDetails);

            if (!$this->hasTaxRuleApplied($taxDetails)) {
                $this->logger->info('Insead_TaxApi: no tax rule matched.', [
                    'customer_tax_class_id' => $customerTaxClassId,
                    'country'               => $countryId,
                ]);
                return null;
            }

            return $taxDetails;

        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: tax engine error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }
    }

    /**
     * Build QuoteDetailsItem list from resolved line items.
     *
     * Using TaxClassKey::TYPE_ID (not TYPE_NAME) so the engine directly uses the
     * class ID without any additional name-to-ID resolution internally.
     *
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

            $productKey = $this->taxClassKeyFactory->create();
            $productKey->setType(TaxClassKey::TYPE_ID)
                ->setValue((string) $item['_product_tax_class_id']);
            $quoteItem->setTaxClassKey($productKey);

            $items[] = $quoteItem;
        }

        return $items;
    }

    /**
     * Return true if at least one line item has an applied tax.
     * Empty appliedTaxes on all items means no rule matched.
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
     * Return a cached virtual billing address for the given country.
     * Postcode '*' matches wildcard tax rate zones.
     */
    private function getVirtualAddress(
        string $countryId
    ): \Magento\Customer\Api\Data\AddressInterface {
        if (!isset($this->virtualAddressCache[$countryId])) {
            $address = $this->addressFactory->create();
            $address->setCountryId($countryId);
            $address->setPostcode('*');
            $address->setCity('Virtual City');
            $address->setStreet(['Virtual Street']);

            $region = $this->regionFactory->create();
            $region->setRegionId(null);
            $address->setRegion($region);

            $this->virtualAddressCache[$countryId] = $address;
        }

        return $this->virtualAddressCache[$countryId];
    }

    // =========================================================================
    // INSEAD tax comment
    // =========================================================================

    /**
     * Retrieve the custom insead_tax_comment field from the matched tax rule.
     *
     * Uses TaxRuleRepositoryInterface (public API) with a fresh SearchCriteriaBuilder
     * instance from factory. Filters by customer and product class IDs using 'finset'
     * condition which matches against Magento's comma-separated many-to-many storage.
     *
     * Returns the first non-empty comment found, or null if not configured.
     */
    private function getInseadTaxComment(
        int $customerTaxClassId,
        int $productTaxClassId,
        string $countryId
    ): ?string {
        try {
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('customer_tax_class_ids', $customerTaxClassId, 'finset')
                ->addFilter('product_tax_class_ids', $productTaxClassId, 'finset')
                ->create();

            $rules = $this->taxRuleRepository->getList($searchCriteria)->getItems();

            foreach ($rules as $rule) {
                $comment = $rule->getData('insead_tax_comment');
                if (!empty($comment)) {
                    return (string) $comment;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: error fetching tax comment: ' . $e->getMessage()
            );
        }

        return null;
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate top-level and per-line-item inputs.
     *
     * @param array[] $lineItemArrays Already normalised plain arrays
     * @return array{valid: bool, message?: string}
     */
    private function validateInput(
        string $legalEntity,
        string $customerType,
        string $billingCountry,
        float $subtotal,
        string $currency,
        array $lineItemArrays,
        string $programmeDeliveryLocation,
        ?string $participantCountry
    ): array {
        if (!in_array(strtoupper($legalEntity), self::LEGAL_ENTITIES, true)) {
            return [
                'valid'   => false,
                'message' => "Invalid legalEntity '{$legalEntity}'. Must be one of: "
                    . implode(', ', self::LEGAL_ENTITIES),
            ];
        }

        if (!in_array(strtoupper($customerType), ['B2B', 'B2C'], true)) {
            return ['valid' => false, 'message' => 'customerType must be B2B or B2C'];
        }

        if (empty($billingCountry) || strlen($billingCountry) !== 2) {
            return ['valid' => false, 'message' => 'billingCountry must be a valid ISO-2 code'];
        }

        if ($subtotal < 0) {
            return ['valid' => false, 'message' => 'subtotal must be zero or a positive number'];
        }

        if (empty($currency) || strlen($currency) !== 3) {
            return ['valid' => false, 'message' => 'currency must be a valid ISO-3 code'];
        }

        if (empty($lineItemArrays)) {
            return ['valid' => false, 'message' => 'lineItems must contain at least one item'];
        }

        if (empty($programmeDeliveryLocation)) {
            return [
                'valid'   => false,
                'message' => 'programmeDeliveryLocation is required (use NA if not applicable)',
            ];
        }

        if ($participantCountry !== null && strlen($participantCountry) !== 2) {
            return ['valid' => false, 'message' => 'participantCountry must be a valid ISO-2 code'];
        }

        foreach ($lineItemArrays as $index => $item) {
            if (empty($item['tax_product_code'])) {
                return ['valid' => false, 'message' => "Line item {$index}: 'tax_product_code' is required"];
            }
            if (!isset($item['price'], $item['qty'])) {
                return ['valid' => false, 'message' => "Line item {$index}: 'price' and 'qty' are required"];
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

    // =========================================================================
    // Response builders
    // =========================================================================

    /**
     * Build success response from Magento TaxDetails.
     *
     * Matches each TaxDetailsItem (keyed by code "item_N") back to the original
     * resolved line item to produce a per-item breakdown including:
     *   name, tax_product_code, price, qty, row_total, tax_rate,
     *   tax_amount, row_total_incl_tax
     *
     * The top-level tax_rate is averaged across all items.
     *
     * @param \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails
     * @param string $currency
     * @param string|null $taxComment
     * @param array $resolvedLineItems Original line items with _sku, _product_tax_class_id
     * @return TaxResponseInterface
     */
    private function buildSuccessResponse(
        \Magento\Tax\Api\Data\TaxDetailsInterface $taxDetails,
        string $currency,
        ?string $taxComment,
        array $resolvedLineItems = []
    ): TaxResponseInterface {
        $engineItems  = $taxDetails->getItems();
        $totalRate    = 0.0;
        $count        = count($engineItems);
        $lineResults  = [];

        // Index original items by their code (item_0, item_1, ...) for fast lookup
        $originalByCode = [];
        foreach ($resolvedLineItems as $index => $item) {
            $originalByCode['item_' . $index] = $item;
        }

        foreach ($engineItems as $engineItem) {
            $itemRate    = 0.0;
            $appliedTaxes = $engineItem->getAppliedTaxes() ?? [];

            foreach ($appliedTaxes as $appliedTax) {
                $itemRate += (float) $appliedTax->getPercent();
            }
            $totalRate += $itemRate;

            $code     = $engineItem->getCode();
            $original = $originalByCode[$code] ?? [];

            $rowTotal      = round((float) $engineItem->getRowTotal(), 2);
            $rowTax        = round((float) $engineItem->getRowTax(), 2);
            $rowTotalIncl  = round($rowTotal + $rowTax, 2);

            $lineResult = $this->lineItemResultFactory->create();
            $lineResult->setCode($code)
                ->setName($original['name'] ?? null)
                ->setTaxProductCode($original['tax_product_code'] ?? '')
                ->setPrice(round((float) ($original['price'] ?? 0), 2))
                ->setQty((float) ($original['qty'] ?? 1))
                ->setRowTotal($rowTotal)
                ->setTaxRate(round($itemRate, 2))
                ->setTaxAmount($rowTax)
                ->setRowTotalInclTax($rowTotalIncl);

            $lineResults[] = $lineResult;
        }

        $subtotal  = (float) $taxDetails->getSubtotal();
        $taxAmount = (float) $taxDetails->getTaxAmount();

        return $this->taxResponseFactory->create()->setData([
            'status'        => self::RESPONSE_SUCCESS,
            'response_code' => 200,
            'tax_rate'      => round($count > 0 ? $totalRate / $count : 0.0, 2),
            'subtotal'      => round($subtotal, 2),
            'tax_amount'    => round($taxAmount, 2),
            'grand_total'   => round($subtotal + $taxAmount, 2),
            'currency'      => $currency,
            'tax_comment'   => $taxComment,
            'line_items'    => $lineResults,
        ]);
    }

    /**
     * Build warning response using the legalEntity fallback rate.
     * Applied when no Magento tax rule matches.
     */
    private function buildWarningResponse(
        string $message,
        string $legalEntity,
        float $subtotal,
        string $currency
    ): TaxResponseInterface {
        $rate      = self::FALLBACK_RATES[strtoupper($legalEntity)] ?? 0.0;
        $taxAmount = round($subtotal * ($rate / 100), 2);

        return $this->taxResponseFactory->create()->setData([
            'status'           => self::RESPONSE_WARNING,
            'response_code'    => 200,
            'message'          => $message . ' Fallback rate applied.',
            'tax_rate'         => round($rate, 2),
            'subtotal'         => round($subtotal, 2),
            'tax_amount'       => $taxAmount,
            'grand_total'      => round($subtotal + $taxAmount, 2),
            'currency'         => $currency,
            'fallback_applied' => true,
            'tax_comment'      => null,
        ]);
    }

    /**
     * Build error response (400) for invalid input or missing SKU.
     */
    private function buildErrorResponse(string $message): TaxResponseInterface
    {
        return $this->taxResponseFactory->create()->setData([
            'status'        => self::RESPONSE_ERROR,
            'response_code' => 400,
            'message'       => $message,
        ]);
    }

    // =========================================================================
    // Audit logging
    // =========================================================================

    /**
     * Persist a full audit log entry for every calculation attempt.
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
        ?bool $isValidVat = null,
        ?bool $gstExempt = null,
        ?bool $isTaxRegistered = null,
        ?string $participantCountry = null,
        ?string $vatNumber = null,
        ?string $billingSystem = null,
        ?string $programmeType = null
    ): void {
        try {
            $responseData    = $response->getData();
            $taxProductCodes = array_unique(
                array_filter(array_column($lineItemArrays, 'tax_product_code'))
            );

            $log = $this->taxLogFactory->create();
            $log->setData([
                'legal_entity'                => $legalEntity,
                'customer_type'               => $customerType,
                'is_valid_vat'                => $isValidVat !== null ? (int) $isValidVat : null,
                'gst_exempt'                  => $gstExempt !== null ? (int) $gstExempt : null,
                'is_tax_registered'           => $isTaxRegistered !== null ? (int) $isTaxRegistered : null,
                'product_class'               => implode(', ', $taxProductCodes),
                'billing_country'             => $billingCountry,
                'programme_delivery_location' => $programmeDeliveryLocation,
                'participant_country'         => $participantCountry,
                'vat_number'                  => $vatNumber,
                'subtotal'                    => $subtotal,
                'currency'                    => $currency,
                'billing_system'              => $billingSystem,
                'programme_type'              => $programmeType,
                'line_items'                  => json_encode($lineItemArrays, JSON_THROW_ON_ERROR),
                'request_data'                => json_encode([
                    'legal_entity'                => $legalEntity,
                    'customer_type'               => $customerType,
                    'is_valid_vat'                => $isValidVat,
                    'gst_exempt'                  => $gstExempt,
                    'is_tax_registered'           => $isTaxRegistered,
                    'billing_country'             => $billingCountry,
                    'programme_delivery_location' => $programmeDeliveryLocation,
                    'participant_country'         => $participantCountry,
                    'vat_number'                  => $vatNumber,
                    'subtotal'                    => $subtotal,
                    'currency'                    => $currency,
                    'billing_system'              => $billingSystem,
                    'programme_type'              => $programmeType,
                    'line_items'                  => $lineItemArrays,
                ], JSON_THROW_ON_ERROR),
                'response_data'               => json_encode($responseData, JSON_THROW_ON_ERROR),
                'status'                      => $responseData['status']      ?? 'unknown',
                'tax_rate'                    => $responseData['tax_rate']    ?? null,
                'tax_amount'                  => $responseData['tax_amount']  ?? null,
                'grand_total'                 => $responseData['grand_total'] ?? null,
                'tax_comment'                 => $responseData['tax_comment'] ?? null,
            ]);

            $log->save();

        } catch (\Exception $e) {
            $this->logger->error(
                'Insead_TaxApi: error saving audit log: ' . $e->getMessage(),
                ['exception' => $e, 'legal_entity' => $legalEntity]
            );
        }
    }
}
