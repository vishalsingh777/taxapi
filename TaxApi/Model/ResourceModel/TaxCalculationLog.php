<?php
declare(strict_types=1);

namespace Insead\TaxApi\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class TaxCalculationLog extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('insead_taxapi_calculation_log', 'log_id');
    }
}
