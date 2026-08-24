<?php
declare(strict_types=1);

namespace Insead\TaxApi\Controller\Adminhtml\Rule;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Tax\Model\Rate\Provider as RatesProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Tax\Model\Calculation\Rate;

class AjaxLoadRates extends \Magento\Tax\Controller\Adminhtml\Rule\AjaxLoadRates implements HttpGetActionInterface
{
    protected $ratesProvider;
    protected $searchCriteriaBuilder;

    public function __construct(
        Context $context,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RatesProvider $ratesProvider
    ) {
        parent::__construct($context, $searchCriteriaBuilder, $ratesProvider);
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->ratesProvider         = $ratesProvider;
    }

    public function execute()
    {
        $ratesPage   = (int) $this->getRequest()->getParam('p');
        $ratesFilter = trim($this->getRequest()->getParam('s', ''));

        if ($this->getRequest()->getParam('isAjax') && $ratesPage > 1) {
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            return $resultJson->setData(['success' => true, 'errorMessage' => '', 'result' => []]);
        }

        try {
            if (!empty($ratesFilter)) {
                $this->searchCriteriaBuilder->addFilter(Rate::KEY_CODE, '%' . $ratesFilter . '%', 'like');
            }
            $searchCriteria = $this->searchCriteriaBuilder
                ->setPageSize(2000)
                ->setCurrentPage(1)
                ->create();

            $response = [
                'success'      => true,
                'errorMessage' => '',
                'result'       => $this->ratesProvider->toOptionArray($searchCriteria),
            ];
        } catch (\Exception $e) {
            $response = ['success' => false, 'errorMessage' => __('An error occurred while loading tax rates.')];
        }

        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $resultJson->setData($response);
    }
}
