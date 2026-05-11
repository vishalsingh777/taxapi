<?php
declare(strict_types=1);
namespace Insead\TaxApi\Controller\Adminhtml\Test;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
class Index extends Action
{
    public function __construct(Context $context, private readonly PageFactory $resultPageFactory)
    {
        parent::__construct($context);
    }
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Insead_TaxApi::test');
        $resultPage->getConfig()->getTitle()->prepend(__('Tax Calculator Test'));
        return $resultPage;
    }
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Insead_TaxApi::test');
    }
}
