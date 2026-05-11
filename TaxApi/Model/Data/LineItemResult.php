<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Model\Data;

use Insead\TaxApi\Api\Data\LineItemResultInterface;
use Magento\Framework\DataObject;

/**
 * Per-line-item tax result DTO implementation.
 */
class LineItemResult extends DataObject implements LineItemResultInterface
{
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return (string) $this->getData('code');
    }

    /**
     * @inheritDoc
     */
    public function setCode(string $code): self
    {
        return $this->setData('code', $code);
    }


    /**
     * @inheritDoc
     */
    public function getName(): ?string
    {
        return $this->getData('name');
    }

    /**
     * @inheritDoc
     */
    public function setName(?string $name): self
    {
        return $this->setData('name', $name);
    }

    /**
     * @inheritDoc
     */
    public function getTaxProductCode(): string
    {
        return (string) $this->getData('tax_product_code');
    }

    /**
     * @inheritDoc
     */
    public function setTaxProductCode(string $taxProductCode): self
    {
        return $this->setData('tax_product_code', $taxProductCode);
    }

    /**
     * @inheritDoc
     */
    public function getPrice(): float
    {
        return (float) $this->getData('price');
    }

    /**
     * @inheritDoc
     */
    public function setPrice(float $price): self
    {
        return $this->setData('price', $price);
    }

    /**
     * @inheritDoc
     */
    public function getQty(): float
    {
        return (float) $this->getData('qty');
    }

    /**
     * @inheritDoc
     */
    public function setQty(float $qty): self
    {
        return $this->setData('qty', $qty);
    }

    /**
     * @inheritDoc
     */
    public function getRowTotal(): float
    {
        return (float) $this->getData('row_total');
    }

    /**
     * @inheritDoc
     */
    public function setRowTotal(float $rowTotal): self
    {
        return $this->setData('row_total', $rowTotal);
    }

    /**
     * @inheritDoc
     */
    public function getTaxRate(): float
    {
        return (float) $this->getData('tax_rate');
    }

    /**
     * @inheritDoc
     */
    public function setTaxRate(float $taxRate): self
    {
        return $this->setData('tax_rate', $taxRate);
    }

    /**
     * @inheritDoc
     */
    public function getTaxAmount(): float
    {
        return (float) $this->getData('tax_amount');
    }

    /**
     * @inheritDoc
     */
    public function setTaxAmount(float $taxAmount): self
    {
        return $this->setData('tax_amount', $taxAmount);
    }

    /**
     * @inheritDoc
     */
    public function getRowTotalInclTax(): float
    {
        return (float) $this->getData('row_total_incl_tax');
    }

    /**
     * @inheritDoc
     */
    public function setRowTotalInclTax(float $rowTotalInclTax): self
    {
        return $this->setData('row_total_incl_tax', $rowTotalInclTax);
    }
}
