<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Api\Data;

/**
 * Response DTO for the INSEAD Tax Calculation API.
 *
 * Magento's ServiceOutputProcessor uses PHP reflection to read the @return
 * annotation from every getter before serialising the response to JSON.
 * Every method must therefore have a complete docblock with @return and @param tags.
 */
interface TaxResponseInterface
{
    /**
     * Get response status: success or error. No warning status — all failures return error.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Set response status.
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Get HTTP-style response code: 200, 400, or 500.
     *
     * @return int
     */
    public function getResponseCode(): int;

    /**
     * Set response code.
     *
     * @param int $code
     * @return $this
     */
    public function setResponseCode(int $code): self;

    /**
     * Get error or warning message. Null on success.
     *
     * @return string|null
     */
    public function getMessage(): ?string;

    /**
     * Set message.
     *
     * @param string|null $message
     * @return $this
     */
    public function setMessage(?string $message): self;

    /**
     * Get applied tax rate percentage (e.g. 9.00 for 9%).
     *
     * @return float|null
     */
    public function getTaxRate(): ?float;

    /**
     * Set tax rate.
     *
     * @param float|null $taxRate
     * @return $this
     */
    public function setTaxRate(?float $taxRate): self;

    /**
     * Get subtotal amount before tax.
     *
     * @return float|null
     */
    public function getSubtotal(): ?float;

    /**
     * Set subtotal.
     *
     * @param float|null $subtotal
     * @return $this
     */
    public function setSubtotal(?float $subtotal): self;

    /**
     * Get calculated tax amount.
     *
     * @return float|null
     */
    public function getTaxAmount(): ?float;

    /**
     * Set tax amount.
     *
     * @param float|null $taxAmount
     * @return $this
     */
    public function setTaxAmount(?float $taxAmount): self;

    /**
     * Get grand total (subtotal + tax amount).
     *
     * @return float|null
     */
    public function getGrandTotal(): ?float;

    /**
     * Set grand total.
     *
     * @param float|null $grandTotal
     * @return $this
     */
    public function setGrandTotal(?float $grandTotal): self;

    /**
     * Get ISO-3 currency code (e.g. EUR, SGD, USD).
     *
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * Set currency code.
     *
     * @param string|null $currency
     * @return $this
     */
    public function setCurrency(?string $currency): self;

    /**
     * Get whether a fallback rate was applied (true when no rule matched).
     *
     * @return bool|null
     */
    public function getFallbackApplied(): ?bool;

    /**
     * Set fallback applied flag.
     *
     * @param bool|null $applied
     * @return $this
     */
    public function setFallbackApplied(?bool $applied): self;

    /**
     * Get per-line-item tax breakdown.
     *
     * @return \Insead\TaxApi\Api\Data\LineItemResultInterface[]|null
     */
    public function getLineItems(): ?array;

    /**
     * Set per-line-item tax breakdown.
     *
     * @param \Insead\TaxApi\Api\Data\LineItemResultInterface[]|null $items
     * @return $this
     */
    public function setLineItems(?array $items): self;
}
