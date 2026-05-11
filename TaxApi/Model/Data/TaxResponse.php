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
    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    /**
     * @inheritDoc
     */
    public function getResponseCode(): int
    {
        return (int) $this->getData('response_code');
    }

    /**
     * @inheritDoc
     */
    public function setResponseCode(int $code): self
    {
        return $this->setData('response_code', $code);
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): ?string
    {
        return $this->getData('message');
    }

    /**
     * @inheritDoc
     */
    public function setMessage(?string $message): self
    {
        return $this->setData('message', $message);
    }

    /**
     * @inheritDoc
     */
    public function getTaxRate(): ?float
    {
        $v = $this->getData('tax_rate');
        return $v !== null ? (float) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setTaxRate(?float $taxRate): self
    {
        return $this->setData('tax_rate', $taxRate);
    }

    /**
     * @inheritDoc
     */
    public function getSubtotal(): ?float
    {
        $v = $this->getData('subtotal');
        return $v !== null ? (float) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setSubtotal(?float $subtotal): self
    {
        return $this->setData('subtotal', $subtotal);
    }

    /**
     * @inheritDoc
     */
    public function getTaxAmount(): ?float
    {
        $v = $this->getData('tax_amount');
        return $v !== null ? (float) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setTaxAmount(?float $taxAmount): self
    {
        return $this->setData('tax_amount', $taxAmount);
    }

    /**
     * @inheritDoc
     */
    public function getGrandTotal(): ?float
    {
        $v = $this->getData('grand_total');
        return $v !== null ? (float) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setGrandTotal(?float $grandTotal): self
    {
        return $this->setData('grand_total', $grandTotal);
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): ?string
    {
        return $this->getData('currency');
    }

    /**
     * @inheritDoc
     */
    public function setCurrency(?string $currency): self
    {
        return $this->setData('currency', $currency);
    }

    /**
     * @inheritDoc
     */
    public function getFallbackApplied(): ?bool
    {
        $v = $this->getData('fallback_applied');
        return $v !== null ? (bool) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setFallbackApplied(?bool $applied): self
    {
        return $this->setData('fallback_applied', $applied);
    }

    /**
     * @inheritDoc
     */
    public function getTaxComment(): ?string
    {
        return $this->getData('tax_comment');
    }

    /**
     * @inheritDoc
     */
    public function setTaxComment(?string $comment): self
    {
        return $this->setData('tax_comment', $comment);
    }

    /**
     * @inheritDoc
     */
    public function getLineItems(): ?array
    {
        return $this->getData('line_items');
    }

    /**
     * @inheritDoc
     */
    public function setLineItems(?array $items): self
    {
        return $this->setData('line_items', $items);
    }
}
