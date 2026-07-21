<?php

declare(strict_types=1);

namespace console\controllers;

use common\models\ClickPesaPayout;
use common\services\ClickPesaService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console jobs for ClickPesa payout retries and status sync.
 *
 * Usage:
 *   php yii clickpesa/process-payouts
 *   php yii clickpesa/sync-payout-status
 *   php yii clickpesa/retry-payout <id>
 */
class ClickpesaController extends Controller
{
    private ClickPesaService $service;

    public function __construct($id, $module, ClickPesaService $service, $config = [])
    {
        $this->service = $service;
        parent::__construct($id, $module, $config);
    }

    /**
     * Process QUEUED / retryable FAILED automatic payouts.
     */
    public function actionProcessPayouts(int $limit = 20): int
    {
        $this->stdout("Processing pending ClickPesa payouts (limit={$limit})...\n", Console::FG_YELLOW);
        $result = $this->service->processPendingPayouts($limit);
        $this->stdout(
            sprintf(
                "Done. processed=%d skipped=%d errors=%d\n",
                $result['processed'],
                $result['skipped'],
                $result['errors']
            ),
            Console::FG_GREEN
        );

        return $result['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Refresh PENDING/PROCESSING payouts from ClickPesa status API.
     */
    public function actionSyncPayoutStatus(int $limit = 50): int
    {
        /** @var ClickPesaPayout[] $rows */
        $rows = ClickPesaPayout::find()
            ->where(['payout_status' => [
                ClickPesaPayout::STATUS_PENDING,
                ClickPesaPayout::STATUS_PROCESSING,
                ClickPesaPayout::STATUS_PREVIEWED,
            ]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $ok = 0;
        $fail = 0;
        foreach ($rows as $row) {
            try {
                $this->service->getPayoutStatus($row->payout_reference, true);
                $ok++;
                $this->stdout("Synced {$row->payout_reference}\n");
            } catch (\Throwable $e) {
                $fail++;
                $this->stderr("Failed {$row->payout_reference}: {$e->getMessage()}\n");
            }
        }

        $this->stdout("Sync complete. ok={$ok} fail={$fail}\n", Console::FG_GREEN);

        return $fail > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Manually retry a failed / awaiting-approval payout by id.
     */
    public function actionRetryPayout(int $id): int
    {
        try {
            $result = $this->service->retryPayout($id, true);
            $this->stdout("Retry ok: " . json_encode($result['payout'] ?? []) . "\n", Console::FG_GREEN);

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Warm ClickPesa token cache.
     */
    public function actionGenerateToken(): int
    {
        try {
            $this->service->generateToken(true);
            $this->stdout("Token cached successfully (value not printed).\n", Console::FG_GREEN);

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
