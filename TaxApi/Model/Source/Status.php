<?php
declare(strict_types=1);

namespace Insead\TaxApi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'success', 'label' => __('Success')],
            ['value' => 'warning', 'label' => __('Warning')],
            ['value' => 'error',   'label' => __('Error')],
        ];
    }
}
