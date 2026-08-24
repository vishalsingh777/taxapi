<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Api\Data;

/**
 * Line item DTO for tax calculation request.
 *
 * Each line item passed by the external billing system must implement this interface.
 * Magento's WebAPI layer deserialises the JSON array into these objects automatically.
 *
 * SKU is constructed from delivery_mode + product_family + duration + programmeDeliveryLocation:
 *   {legalEntity}_{deliveryModePrefix}_{productFamily}_{durationSuffix}_{programmeDeliveryLocation}
 *
 * Examples:
 *   OEP + F2F + 10 days  → IP_OEP_GT1W  → FBL_IP_OEP_GT1W_FBL
 *   OEP + F2F + 3 days   → IP_OEP_LT1W  → FBL_IP_OEP_LT1W_FBL
 *   OEP + Online         → OL_OEP_NA    → FBL_OL_OEP_NA_NA
 *   CSP + F2F + 14 days  → IP_CSP_GT1W  → SGP_IP_CSP_GT1W_SGP
 *   CST + F2F            → IP_CST_NA    → FBL_IP_CST_NA_FBL
 *   DP  + Online         → OL_DP_NA     → UAE_OL_DP_NA_NA
 *   OOP + Online         → OL_OOP_NA    → FBL_OL_OOP_NA_NA
 */
interface LineItemInterface
{
    /**
     * Get programme family.
     * Allowed values: OEP, CSP, CST, DP, OOP
     *
     * @return string
     */
    public function getProductFamily(): string;

    /**
     * Set programme family.
     *
     * @param string $productFamily
     * @return $this
     */
    public function setProductFamily(string $productFamily): self;

    /**
     * Get delivery mode.
     * Allowed values: Online, F2F, Live Virtual
     *
     * @return string
     */
    public function getDeliveryMode(): string;

    /**
     * Set delivery mode.
     *
     * @param string $deliveryMode
     * @return $this
     */
    public function setDeliveryMode(string $deliveryMode): self;

    /**
     * Get programme duration in days.
     * Only meaningful for OEP or CSP with delivery_mode = F2F.
     * Used to derive SHORT or LONG.
     * Missing / null / zero defaults to NA.
     *
     * @return string|null
     */
    public function getDuration(): ?string;

    /**
     * Set duration in days.
     *
     * @param string|null $duration
     * @return $this
     */
    public function setDuration(?string $duration): self;

    /**
     * Get unit price (must be >= 0).
     *
     * @return float
     */
    public function getPrice(): float;

    /**
     * Set unit price.
     *
     * @param float $price
     * @return $this
     */
    public function setPrice(float $price): self;

    /**
     * Get quantity (must be > 0).
     *
     * @return float
     */
    public function getQty(): float;

    /**
     * Set quantity.
     *
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * Get product name — stored in audit log only, not used for tax calculation.
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * Set product name.
     *
     * @param string|null $name
     * @return $this
     */
    public function setName(?string $name): self;
}
