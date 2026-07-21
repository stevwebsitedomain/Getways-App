<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\ClickPesaSetting $settings */
/** @var string $maskedDestination */
/** @var yii\data\ActiveDataProvider $provider */

use common\models\ClickPesaPayout;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'Automatic Payout';
$this->params['breadcrumbs'][] = ['label' => 'ClickPesa', 'url' => ['control-numbers']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="clickpesa-payouts">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <div class="btn-group">
            <?= Html::a('Control Numbers', ['control-numbers'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
            <?= Html::a('Settings', ['settings'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        </div>
    </div>

    <div class="alert alert-<?= $settings->auto_payout_enabled ? 'warning' : 'secondary' ?>">
        <strong>Auto payout:</strong>
        <?= $settings->auto_payout_enabled ? 'ON' : 'OFF' ?>
        &nbsp;|&nbsp;
        <strong>Destination:</strong> <?= Html::encode($maskedDestination) ?>
        &nbsp;|&nbsp;
        <strong>Mode:</strong>
        <?php
        if (!$settings->auto_payout_enabled) {
            echo 'TEST (webhooks saved, no payout)';
        } elseif ($settings->require_manual_approval) {
            echo 'MANUAL APPROVAL';
        } else {
            echo 'LIVE AUTO PAYOUT';
        }
        ?>
    </div>

    <?= GridView::widget([
        'dataProvider' => $provider,
        'tableOptions' => ['class' => 'table table-striped table-sm align-middle'],
        'columns' => [
            'id',
            [
                'label' => 'Payment ref',
                'value' => static function (ClickPesaPayout $m) {
                    return $m->payment ? $m->payment->order_reference : ('#' . $m->payment_id);
                },
            ],
            'payout_reference',
            'destination_masked',
            [
                'attribute' => 'amount',
                'value' => static fn(ClickPesaPayout $m) => number_format((float) $m->amount, 2),
            ],
            [
                'attribute' => 'fee',
                'value' => static fn(ClickPesaPayout $m) => $m->fee !== null ? number_format((float) $m->fee, 2) : '—',
            ],
            'payout_status',
            'provider',
            [
                'attribute' => 'last_error',
                'format' => 'ntext',
                'contentOptions' => ['style' => 'max-width:220px;white-space:normal;'],
            ],
            [
                'class' => ActionColumn::class,
                'template' => '{retry} {approve}',
                'buttons' => [
                    'retry' => static function ($url, ClickPesaPayout $model) {
                        if (!in_array($model->payout_status, [ClickPesaPayout::STATUS_FAILED], true)) {
                            return '';
                        }

                        return Html::a('Retry', ['retry-payout', 'id' => $model->id], [
                            'class' => 'btn btn-sm btn-outline-danger',
                            'data-method' => 'post',
                            'data-confirm' => 'Retry this payout?',
                        ]);
                    },
                    'approve' => static function ($url, ClickPesaPayout $model) {
                        if ($model->payout_status !== ClickPesaPayout::STATUS_AWAITING_APPROVAL) {
                            return '';
                        }

                        return Html::a('Approve', ['approve-payout', 'id' => $model->id], [
                            'class' => 'btn btn-sm btn-success',
                            'data-method' => 'post',
                            'data-confirm' => 'Approve and send this payout?',
                        ]);
                    },
                ],
            ],
        ],
    ]) ?>
</div>
