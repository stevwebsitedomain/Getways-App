<?php

declare(strict_types=1);

namespace common\tests\Unit\Services;

use Codeception\Test\Unit;
use common\models\ClickPesaSetting;
use common\models\ClickPesaWebhookEvent;
use common\services\ClickPesaService;

/**
 * ClickPesa unit tests (no live API calls).
 */
final class ClickPesaServiceTest extends Unit
{
    private ClickPesaService $service;

    protected function _before(): void
    {
        $this->service = new ClickPesaService();
    }

    public function testNormalizePhoneFromLocalFormat(): void
    {
        verify($this->service->normalizePhone('0715296092'))->equals('255715296092');
        verify($this->service->normalizePhone('+255715296092'))->equals('255715296092');
        verify($this->service->normalizePhone('715296092'))->equals('255715296092');
    }

    public function testMaskPhone(): void
    {
        $masked = ClickPesaSetting::maskPhone('255715296092');
        verify($masked)->equals('2557******92');
        verify(str_contains($masked, '152960'))->false();
    }

    public function testChecksumIsDeterministicCanonicalHmac(): void
    {
        $key = 'test-checksum-key';
        $payload = ['amount' => 1000, 'phoneNumber' => '255715296092', 'currency' => 'TZS'];
        $a = $this->service->createPayloadChecksum($key, $payload);
        $b = $this->service->createPayloadChecksum($key, ['currency' => 'TZS', 'amount' => 1000, 'phoneNumber' => '255715296092']);
        verify($a)->equals($b);
        verify(strlen($a))->equals(64);
    }

    public function testWebhookEventHashIsStableForSameId(): void
    {
        $payload = ['id' => 'evt-123', 'status' => 'SUCCESS'];
        $h1 = ClickPesaWebhookEvent::hashPayload('{"id":"evt-123"}', $payload);
        $h2 = ClickPesaWebhookEvent::hashPayload('different-raw', $payload);
        verify($h1)->equals($h2);
    }

    public function testInvalidAmountRejected(): void
    {
        $this->expectException(\yii\web\BadRequestHttpException::class);
        $this->service->createControlNumber([
            'order_id' => 'TIS-1',
            'amount' => 0,
            'description' => 'test',
            'payment_mode' => 'EXACT',
        ]);
    }

    public function testInvalidPaymentModeRejected(): void
    {
        $this->expectException(\yii\web\BadRequestHttpException::class);
        $this->service->createControlNumber([
            'order_id' => 'TIS-1',
            'amount' => 1000,
            'description' => 'test',
            'payment_mode' => 'WRONG',
        ]);
    }

    public function testDefaultPayoutPhoneConstant(): void
    {
        verify(ClickPesaSetting::DEFAULT_PHONE)->equals('255715296092');
    }
}
