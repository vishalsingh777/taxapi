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

            $legalEntity               = strtoupper(trim($params['legal_entity']   ?? ''));
            $customerType              = strtoupper(trim($params['customer_type']  ?? ''));
            $taxStatus               = !empty($params['tax_status']) ? trim($params['tax_status']) : null;
            $gstDeclarationAccepted  = isset($params['gst_declaration_accepted'])
                                        ? (bool) $params['gst_declaration_accepted']
                                        : null;
            $outsideSg               = isset($params['outside_sg'])
                                        ? (bool) $params['outside_sg']
                                        : null;
            $billingCountry            = strtoupper(trim($params['billing_country'] ?? ''));
            $subtotal                  = (float) ($params['subtotal']              ?? 0);
            $currency                  = strtoupper(trim($params['currency']       ?? 'EUR'));
            $programmeDeliveryLocation = strtoupper(trim($params['programme_delivery_location'] ?? 'NA'));
            $vatNumber                 = !empty($params['vat_number'])    ? $params['vat_number']    : null;
            $billingSystem             = !empty($params['billing_system']) ? $params['billing_system'] : null;

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
                $taxStatus,
                $gstDeclarationAccepted,
                $outsideSg,
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
