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
    public function getProductFamily(): string
    {
        return (string) $this->getData('product_family');
    }

    public function setProductFamily(string $productFamily): self
    {
        return $this->setData('product_family', $productFamily);
    }

    public function getDeliveryMode(): string
    {
        return (string) $this->getData('delivery_mode');
    }

    public function setDeliveryMode(string $deliveryMode): self
    {
        return $this->setData('delivery_mode', $deliveryMode);
    }

    public function getDuration(): ?string
    {
        $v = $this->getData('duration');
        return $v !== null ? (string) $v : null;
    }

    public function setDuration(?string $duration): self
    {
        return $this->setData('duration', $duration);
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
     */
    public function toArray(array $keys = []): array
    {
        return [
            'product_family' => $this->getProductFamily(),
            'delivery_mode'  => $this->getDeliveryMode(),
            'duration'       => $this->getDuration(),
            'price'          => $this->getPrice(),
            'qty'            => $this->getQty(),
            'name'           => $this->getName() ?? '',
        ];
    }
}
