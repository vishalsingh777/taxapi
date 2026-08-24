<?php
declare(strict_types=1);

namespace Insead\TaxApi\Model\ResourceModel\TaxCalculationLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct()
    {
        $this->_init(
            \Insead\TaxApi\Model\TaxCalculationLog::class,
            \Insead\TaxApi\Model\ResourceModel\TaxCalculationLog::class
        );
    }
}
