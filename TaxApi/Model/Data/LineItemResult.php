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
    public function getCode(): string
    {
        return (string) $this->getData('code');
    }

    public function setCode(string $code): self
    {
        return $this->setData('code', $code);
    }

    public function getName(): ?string
    {
        return $this->getData('name');
    }

    public function setName(?string $name): self
    {
        return $this->setData('name', $name);
    }

    public function getTaxProductCode(): string
    {
        return (string) $this->getData('tax_product_code');
    }

    public function setTaxProductCode(string $taxProductCode): self
    {
        return $this->setData('tax_product_code', $taxProductCode);
    }

    public function getPrice(): float
    {
        return (float) $this->getData('price');
    }

    public function setPrice(float $price): self
    {
        return $this->setData('price', $price);
    }

    public function getQty(): float
    {
        return (float) $this->getData('qty');
    }

    public function setQty(float $qty): self
    {
        return $this->setData('qty', $qty);
    }

    public function getRowTotal(): float
    {
        return (float) $this->getData('row_total');
    }

    public function setRowTotal(float $rowTotal): self
    {
        return $this->setData('row_total', $rowTotal);
    }

    public function getTaxRate(): float
    {
        return (float) $this->getData('tax_rate');
    }

    public function setTaxRate(float $taxRate): self
    {
        return $this->setData('tax_rate', $taxRate);
    }

    public function getTaxAmount(): float
    {
        return (float) $this->getData('tax_amount');
    }

    public function setTaxAmount(float $taxAmount): self
    {
        return $this->setData('tax_amount', $taxAmount);
    }

    public function getRowTotalInclTax(): float
    {
        return (float) $this->getData('row_total_incl_tax');
    }

    public function setRowTotalInclTax(float $rowTotalInclTax): self
    {
        return $this->setData('row_total_incl_tax', $rowTotalInclTax);
    }

    public function getTaxComment(): ?string
    {
        return $this->getData('tax_comment');
    }

    public function setTaxComment(?string $taxComment): self
    {
        return $this->setData('tax_comment', $taxComment);
    }

    public function getFusionTaxCode(): ?string
    {
        return $this->getData('fusion_tax_code');
    }

    public function setFusionTaxCode(?string $fusionTaxCode): self
    {
        return $this->setData('fusion_tax_code', $fusionTaxCode);
    }

    public function getTaxArticle(): ?string
    {
        return $this->getData('tax_article');
    }

    public function setTaxArticle(?string $taxArticle): self
    {
        return $this->setData('tax_article', $taxArticle);
    }
}
