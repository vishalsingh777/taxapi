<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Insead\TaxApi\Api\TaxCalculationInterface;

class Calculate extends Action
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TaxCalculationInterface $taxCalculation
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $params = $this->getRequest()->getParams();

            $legalEntity                = strtoupper(trim($params['legal_entity']   ?? ''));
            $customerType               = strtoupper(trim($params['customer_type']  ?? ''));
            $isValidVat                 = isset($params['is_valid_vat'])   ? (bool) $params['is_valid_vat']   : null;
            $gstExempt                  = isset($params['gst_exempt'])     ? (bool) $params['gst_exempt']     : null;
            $isTaxRegistered            = isset($params['is_tax_registered']) ? (bool) $params['is_tax_registered'] : null;
            $programmeType              = !empty($params['programme_type'])
                                            ? strtoupper(trim($params['programme_type']))
                                            : null;
            $billingCountry             = strtoupper(trim($params['billing_country'] ?? ''));
            $subtotal                   = (float) ($params['subtotal']              ?? 0);
            $currency                   = strtoupper(trim($params['currency']       ?? 'EUR'));
            $programmeDeliveryLocation  = strtoupper(trim($params['programme_delivery_location'] ?? 'NA'));
            $participantCountry         = !empty($params['participant_country'])
                                            ? strtoupper(trim($params['participant_country']))
                                            : null;
            $vatNumber                  = !empty($params['vat_number'])   ? $params['vat_number']   : null;
            $billingSystem              = !empty($params['billing_system']) ? $params['billing_system'] : null;

            // Validate and decode line items
            $lineItems = [];
            if (!empty($params['line_items'])) {
                $decoded = json_decode($params['line_items'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $lineItems = $decoded;
                } else {
                    return $result->setData([
                        'status'        => 'error',
                        'response_code' => 400,
                        'message'       => 'line_items must be a valid JSON array',
                    ]);
                }
            }

            $response = $this->taxCalculation->calculateTax(
                $legalEntity,
                $customerType,
                $billingCountry,
                $subtotal,
                $currency,
                $lineItems,
                $programmeDeliveryLocation,
                $programmeType,
                $isValidVat,
                $gstExempt,
                $isTaxRegistered,
                $participantCountry,
                $vatNumber,
                $billingSystem
            );

            return $result->setData($response->getData());

        } catch (\Exception $e) {
            return $result->setData([
                'status'        => 'error',
                'response_code' => 500,
                'message'       => $e->getMessage(),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Insead_TaxApi::test');
    }
}
