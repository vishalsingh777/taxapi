<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Api\Data;

/**
 * Per-line-item tax result returned in the API response.
 *
 * Magento's ServiceOutputProcessor reflects on every getter to serialise
 * the response — every method must have a complete @return docblock.
 */
interface LineItemResultInterface
{
    /**
     * Get the item index/code (e.g. item_0, item_1).
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Set item code.
     *
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self;

    /**
     * Get product name (passed in request, echoed back for reference).
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * Set name.
     *
     * @param string|null $name
     * @return $this
     */
    public function setName(?string $name): self;

    /**
     * Get the tax product code descriptor (product_family_delivery_mode).
     *
     * @return string
     */
    public function getTaxProductCode(): string;

    /**
     * Set tax product code descriptor.
     *
     * @param string $taxProductCode
     * @return $this
     */
    public function setTaxProductCode(string $taxProductCode): self;

    /**
     * Get unit price.
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
     * Get quantity.
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
     * Get row total before tax (price x qty).
     *
     * @return float
     */
    public function getRowTotal(): float;

    /**
     * Set row total.
     *
     * @param float $rowTotal
     * @return $this
     */
    public function setRowTotal(float $rowTotal): self;

    /**
     * Get tax rate percentage applied to this line item.
     *
     * @return float
     */
    public function getTaxRate(): float;

    /**
     * Set tax rate.
     *
     * @param float $taxRate
     * @return $this
     */
    public function setTaxRate(float $taxRate): self;

    /**
     * Get tax amount for this line item.
     *
     * @return float
     */
    public function getTaxAmount(): float;

    /**
     * Set tax amount.
     *
     * @param float $taxAmount
     * @return $this
     */
    public function setTaxAmount(float $taxAmount): self;

    /**
     * Get row grand total (row total + tax amount).
     *
     * @return float
     */
    public function getRowTotalInclTax(): float;

    /**
     * Set row grand total.
     *
     * @param float $rowTotalInclTax
     * @return $this
     */
    public function setRowTotalInclTax(float $rowTotalInclTax): self;

    /**
     * Get the INSEAD tax comment from the matched tax rule for this line item.
     * Used for invoice display (e.g. "GST @9%", "Reverse-charge: Customer to pay the VAT").
     *
     * @return string|null
     */
    public function getTaxComment(): ?string;

    /**
     * Set tax comment.
     *
     * @param string|null $taxComment
     * @return $this
     */
    public function setTaxComment(?string $taxComment): self;

    /**
     * Get the Fusion tax code from the matched tax rule for this line item.
     * Used for integration with Fusion (e.g. to determine tax recovery rate).
     *
     * @return string|null
     */
    public function getFusionTaxCode(): ?string;

    /**
     * Set Fusion tax code.
     *
     * @param string|null $fusionTaxCode
     * @return $this
     */
    public function setFusionTaxCode(?string $fusionTaxCode): self;

    /**
     * Get the tax article from the matched tax rule for this line item.
     * Used for invoice display (e.g. "Autoliquidation – Article 196 de la Directive 2006/112/CE").
     *
     * @return string|null
     */
    public function getTaxArticle(): ?string;

    /**
     * Set tax article.
     *
     * @param string|null $taxArticle
     * @return $this
     */
    public function setTaxArticle(?string $taxArticle): self;
}
