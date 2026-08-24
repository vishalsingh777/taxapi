<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Model\Data;

use Insead\TaxApi\Api\Data\TaxResponseInterface;
use Magento\Framework\DataObject;

/**
 * Response DTO implementation.
 * Backed by DataObject so setData() / getData() handle all fields.
 */
class TaxResponse extends DataObject implements TaxResponseInterface
{
    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    public function getResponseCode(): int
    {
        return (int) $this->getData('response_code');
    }

    public function setResponseCode(int $code): self
    {
        return $this->setData('response_code', $code);
    }

    public function getMessage(): ?string
    {
        return $this->getData('message');
    }

    public function setMessage(?string $message): self
    {
        return $this->setData('message', $message);
    }

    public function getTaxRate(): ?float
    {
        $v = $this->getData('tax_rate');
        return $v !== null ? (float) $v : null;
    }

    public function setTaxRate(?float $taxRate): self
    {
        return $this->setData('tax_rate', $taxRate);
    }

    public function getSubtotal(): ?float
    {
        $v = $this->getData('subtotal');
        return $v !== null ? (float) $v : null;
    }

    public function setSubtotal(?float $subtotal): self
    {
        return $this->setData('subtotal', $subtotal);
    }

    public function getTaxAmount(): ?float
    {
        $v = $this->getData('tax_amount');
        return $v !== null ? (float) $v : null;
    }

    public function setTaxAmount(?float $taxAmount): self
    {
        return $this->setData('tax_amount', $taxAmount);
    }

    public function getGrandTotal(): ?float
    {
        $v = $this->getData('grand_total');
        return $v !== null ? (float) $v : null;
    }

    public function setGrandTotal(?float $grandTotal): self
    {
        return $this->setData('grand_total', $grandTotal);
    }

    public function getCurrency(): ?string
    {
        return $this->getData('currency');
    }

    public function setCurrency(?string $currency): self
    {
        return $this->setData('currency', $currency);
    }

    public function getFallbackApplied(): ?bool
    {
        $v = $this->getData('fallback_applied');
        return $v !== null ? (bool) $v : null;
    }

    public function setFallbackApplied(?bool $applied): self
    {
        return $this->setData('fallback_applied', $applied);
    }

    public function getLineItems(): ?array
    {
        return $this->getData('line_items');
    }

    public function setLineItems(?array $items): self
    {
        return $this->setData('line_items', $items);
    }
}
