<?php
declare(strict_types=1);
namespace Insead\TaxApi\Model\ResourceModel\TaxCalculationLog\Grid;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();
        return $this;
    }
}
