<?php

declare(strict_types=1);

namespace backend\controllers;

use common\models\ClickPesaPayout;
use common\models\ClickPesaSetting;
use common\models\ClickPesaSettingAudit;
use common\models\ClickPesaTransaction;
use common\models\User;
use common\services\ClickPesaService;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Admin: ClickPesa control numbers, automatic payout settings & history.
 */
class ClickpesaController extends Controller
{
    private ClickPesaService $clickPesa;

    public function __construct($id, $module, ClickPesaService $clickPesa, $config = [])
    {
        $this->clickPesa = $clickPesa;
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'save-settings' => ['POST'],
                    'create-control-number' => ['POST'],
                    'retry-payout' => ['POST'],
                    'approve-payout' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        return $this->redirect(['control-numbers']);
    }

    public function actionControlNumbers(): string
    {
        $provider = new ActiveDataProvider([
            'query' => ClickPesaTransaction::find()
                ->where(['channel' => 'billpay'])
                ->orWhere(['not', ['control_number' => null]])
                ->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);

        return $this->render('control-numbers', [
            'provider' => $provider,
        ]);
    }

    public function actionCreateControlNumber(): Response
    {
        $req = Yii::$app->request;
        $orderId = trim((string) $req->post('order_id', ''));
        $amount = (float) $req->post('amount', 0);
        $description = trim((string) $req->post('description', ''));
        $paymentMode = (string) $req->post('payment_mode', ClickPesaTransaction::MODE_EXACT);

        try {
            $result = $this->clickPesa->createControlNumber([
                'order_id' => $orderId,
                'amount' => $amount,
                'description' => $description,
                'payment_mode' => $paymentMode,
            ], Yii::$app->user->id ? (int) Yii::$app->user->id : null);
            Yii::$app->session->setFlash('success', 'Control number created: ' . ($result['controlNumber'] ?? ''));
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['control-numbers']);
    }

    public function actionPayouts(): string
    {
        $settings = ClickPesaSetting::current();
        $provider = new ActiveDataProvider([
            'query' => ClickPesaPayout::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);

        return $this->render('payouts', [
            'settings' => $settings,
            'provider' => $provider,
            'maskedDestination' => $settings->getMaskedDestination(),
        ]);
    }

    public function actionSettings(): string
    {
        $settings = ClickPesaSetting::current();

        return $this->render('settings', [
            'settings' => $settings,
            'maskedDestination' => $settings->getMaskedDestination(),
        ]);
    }

    public function actionSaveSettings(): Response
    {
        try {
            $settings = ClickPesaSetting::current();
            $post = Yii::$app->request->post();
            $password = (string) ($post['admin_password'] ?? '');

            $enablingAuto = !empty($post['auto_payout_enabled']) && !(bool) $settings->auto_payout_enabled;
            $changingDestination = trim((string) ($post['destination_phone'] ?? '')) !== '';

            if ($enablingAuto || $changingDestination) {
                $this->assertAdminPassword($password);
            }

            $before = [
                'auto_payout_enabled' => (bool) $settings->auto_payout_enabled,
                'destination_masked' => $settings->getMaskedDestination(),
                'payout_percentage' => (float) $settings->payout_percentage,
                'minimum_amount' => (float) $settings->minimum_amount,
                'daily_limit' => (float) $settings->daily_limit,
                'delay_seconds' => (int) $settings->delay_seconds,
                'require_manual_approval' => (bool) $settings->require_manual_approval,
                'destination_type' => $settings->destination_type,
            ];

            $settings->auto_payout_enabled = !empty($post['auto_payout_enabled']) ? 1 : 0;
            $settings->mode = (string) ($post['mode'] ?? ClickPesaSetting::MODE_TEST);
            $settings->destination_type = (string) ($post['destination_type'] ?? ClickPesaSetting::DESTINATION_MOBILE);
            $settings->mobile_provider = trim((string) ($post['mobile_provider'] ?? ''));
            $settings->payout_percentage = (float) ($post['payout_percentage'] ?? 100);
            $settings->minimum_amount = (float) ($post['minimum_amount'] ?? 1000);
            $settings->daily_limit = (float) ($post['daily_limit'] ?? 0);
            $settings->delay_seconds = (int) ($post['delay_seconds'] ?? 60);
            $settings->require_manual_approval = !empty($post['require_manual_approval']) ? 1 : 0;

            if ($changingDestination) {
                $settings->setDestinationPhone((string) $post['destination_phone']);
            }

            if ($settings->destination_type === ClickPesaSetting::DESTINATION_BANK) {
                $settings->bank_name = trim((string) ($post['bank_name'] ?? ''));
                $settings->bank_account_name = trim((string) ($post['bank_account_name'] ?? ''));
                $settings->bank_bic_swift = trim((string) ($post['bank_bic_swift'] ?? ''));
                $bankAcct = trim((string) ($post['bank_account_number'] ?? ''));
                if ($bankAcct !== '') {
                    $settings->bank_account_number_enc = ClickPesaSetting::encryptValue($bankAcct);
                }
            }

            if ($settings->mode === ClickPesaSetting::MODE_TEST) {
                $settings->auto_payout_enabled = 0;
            }
            if ($settings->mode === ClickPesaSetting::MODE_MANUAL_APPROVAL) {
                $settings->require_manual_approval = 1;
            }

            if (!$settings->save()) {
                Yii::$app->session->setFlash('error', 'Failed to save settings.');
                return $this->redirect(['settings']);
            }

            ClickPesaSettingAudit::log('settings_updated', [
                'before' => $before,
                'after' => [
                    'auto_payout_enabled' => (bool) $settings->auto_payout_enabled,
                    'destination_masked' => $settings->getMaskedDestination(),
                    'payout_percentage' => (float) $settings->payout_percentage,
                    'minimum_amount' => (float) $settings->minimum_amount,
                    'daily_limit' => (float) $settings->daily_limit,
                    'delay_seconds' => (int) $settings->delay_seconds,
                    'require_manual_approval' => (bool) $settings->require_manual_approval,
                    'destination_type' => $settings->destination_type,
                ],
            ], Yii::$app->user->id ? (int) Yii::$app->user->id : null, Yii::$app->request->userIP);

            Yii::$app->session->setFlash('success', 'Payout settings saved.');
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['settings']);
    }

    public function actionRetryPayout(int $id): Response
    {
        try {
            $this->clickPesa->retryPayout($id, true);
            Yii::$app->session->setFlash('success', 'Payout retry submitted.');
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['payouts']);
    }

    public function actionApprovePayout(int $id): Response
    {
        $payout = ClickPesaPayout::findOne($id);
        if ($payout === null) {
            throw new NotFoundHttpException('Payout not found.');
        }

        try {
            $this->clickPesa->retryPayout($id, true);
            Yii::$app->session->setFlash('success', 'Payout approved and submitted.');
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

        return $this->redirect(['payouts']);
    }

    private function assertAdminPassword(string $password): void
    {
        if ($password === '') {
            throw new BadRequestHttpException('Admin password is required to change payout destination or enable automatic payout.');
        }

        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if ($user === null || !$user->validatePassword($password)) {
            throw new BadRequestHttpException('Invalid admin password.');
        }
    }
}
