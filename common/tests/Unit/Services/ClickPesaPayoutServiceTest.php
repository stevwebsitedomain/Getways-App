<?php

declare(strict_types=1);

namespace common\tests\Unit\Services;

use Codeception\Test\Unit;
use common\models\ClickPesaSetting;
use common\services\ClickPesaPayoutService;
use common\services\ClickPesaService;

/**
 * ClickPesa payout unit tests (no live API calls).
 */
final class ClickPesaPayoutServiceTest extends Unit
{
    private ClickPesaPayoutService $payoutService;
    private ClickPesaService $service;

    protected function _before(): void
    {
        $this->payoutService = new ClickPesaPayoutService();
        $this->service = new ClickPesaService();
    }

    public function testNormalizePhoneStrictTwelveDigits(): void
    {
        verify($this->payoutService->normalizePhoneNumber('0715296092'))->equals('255715296092');
        verify($this->payoutService->normalizePhoneNumber('+255 715 296 092'))->equals('255715296092');
        verify($this->payoutService->normalizePhoneNumber('255715296092'))->equals('255715296092');
        verify($this->payoutService->normalizePhoneNumber('0715-296-092'))->equals('255715296092');
    }

    public function testInvalidPhoneRejected(): void
    {
        $this->expectException(\yii\web\BadRequestHttpException::class);
        $this->payoutService->normalizePhoneNumber('12345');
    }

    public function testChecksumTestVectorKeyOrderIndependent(): void
    {
        $key = 'test-checksum-key';
        $a = $this->payoutService->createPayloadChecksum($key, [
            'amount' => 10000,
            'phoneNumber' => '255715296092',
            'currency' => 'TZS',
            'orderReference' => 'PAYOUT202608200001',
        ]);
        $b = $this->payoutService->createPayloadChecksum($key, [
            'currency' => 'TZS',
            'orderReference' => 'PAYOUT202608200001',
            'phoneNumber' => '255715296092',
            'amount' => 10000,
        ]);
        verify($a)->equals($b);
        verify(strlen($a))->equals(64);
    }

    public function testValidatePayoutRequestRequiresPositiveAmount(): void
    {
        $this->expectException(\yii\web\BadRequestHttpException::class);
        $this->payoutService->validatePayoutRequest([
            'amount' => 0,
            'phoneNumber' => '255715296092',
            'orderReference' => 'PAYOUT-TEST-1',
        ]);
    }

    public function testDefaultPayoutPhone(): void
    {
        verify(ClickPesaSetting::DEFAULT_PHONE)->equals('255765149991');
    }

    public function testLegacyServiceNormalizePhone(): void
    {
        verify($this->service->normalizePhone('0715296092'))->equals('255715296092');
    }
}
