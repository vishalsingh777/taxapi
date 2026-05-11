<?php
declare(strict_types=1);
namespace Insead\TaxApi\Model;
use Magento\Framework\Model\AbstractModel;
class TaxCalculationLog extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Insead\TaxApi\Model\ResourceModel\TaxCalculationLog::class);
    }
}
