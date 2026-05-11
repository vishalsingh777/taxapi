<?php
/**
 * Copyright © Insead. All rights reserved.
 */
declare(strict_types=1);

namespace Insead\TaxApi\Cron;

use Insead\TaxApi\Model\ResourceModel\TaxCalculationLog\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Nightly cron: purge tax calculation logs older than configured retention period.
 *
 * Config path : insead_taxapi/log_cleanup/retention_days
 * Default     : 90 days
 * Schedule    : Daily at 02:00 server time
 * Disable     : Set retention_days = 0
 */
class CleanupLogs
{
    private const CONFIG_PATH = 'insead_taxapi/log_cleanup/retention_days';

    public function __construct(
        private readonly CollectionFactory    $collectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime            $dateTime,
        private readonly LoggerInterface     $logger
    ) {
    }

    public function execute(): void
    {
        $retentionDays = (int) $this->scopeConfig->getValue(self::CONFIG_PATH);

        if ($retentionDays <= 0) {
            $this->logger->info('Insead_TaxApi log cleanup disabled (retention_days = 0).');
            return;
        }

        $cutoffDate = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $this->logger->info(
            "Insead_TaxApi log cleanup: removing records older than {$retentionDays} days (before {$cutoffDate})."
        );

        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('created_at', ['lt' => $cutoffDate]);
            $deleted = 0;
            foreach ($collection->getItems() as $log) {
                $log->delete();
                $deleted++;
            }
            $this->logger->info("Insead_TaxApi log cleanup complete: {$deleted} record(s) deleted.");
        } catch (\Exception $e) {
            $this->logger->error('Insead_TaxApi log cleanup failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
