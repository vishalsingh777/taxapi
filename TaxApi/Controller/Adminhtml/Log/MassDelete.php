<?php
declare(strict_types=1);

namespace Insead\TaxApi\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Insead\TaxApi\Model\ResourceModel\TaxCalculationLog\CollectionFactory;

class MassDelete extends Action
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $deleted = 0;
            foreach ($collection->getItems() as $log) {
                $log->delete();
                $deleted++;
            }
            $this->messageManager->addSuccessMessage(
                __('A total of %1 log record(s) have been deleted.', $deleted)
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Something went wrong while deleting log records.'));
        }
        return $resultRedirect->setPath('*/*/index');
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Insead_TaxApi::log');
    }
}
