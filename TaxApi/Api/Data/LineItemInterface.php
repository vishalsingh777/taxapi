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
 */
interface LineItemInterface
{
    /**
     * Get structured programme code — used to construct the Magento product SKU.
     * Examples: OL_OOP_NA, IP_OEP_SHORT, IP_CSP_LONG, OL_CASES_NA, OL_FOOD_NA
     *
     * @return string
     */
    public function getTaxProductCode(): string;

    /**
     * Set tax product code.
     *
     * @param string $taxProductCode
     * @return $this
     */
    public function setTaxProductCode(string $taxProductCode): self;

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
