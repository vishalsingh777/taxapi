<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Model\Data;

use Insead\TaxApi\Api\Data\LineItemInterface;
use Magento\Framework\DataObject;

/**
 * Line item DTO implementation.
 * Magento's WebAPI layer instantiates this class for each element in the lineItems JSON array.
 */
class LineItem extends DataObject implements LineItemInterface
{
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

    public function getSku(): ?string
    {
        return $this->getData('sku');
    }

    public function setSku(?string $sku): self
    {
        return $this->setData('sku', $sku);
    }

    public function getName(): ?string
    {
        return $this->getData('name');
    }

    public function setName(?string $name): self
    {
        return $this->setData('name', $name);
    }

    /**
     * Convert to plain array for internal use in TaxCalculation service.
     * Allows the rest of the code to work with arrays as before.
     */
    public function toArray(array $keys = []): array
    {
        return [
            'tax_product_code' => $this->getTaxProductCode(),
            'price'            => $this->getPrice(),
            'qty'              => $this->getQty(),
            'name'             => $this->getName(),
        ];
    }
}
