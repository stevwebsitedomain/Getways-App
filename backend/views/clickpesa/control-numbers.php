<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $provider */

use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'ClickPesa Control Numbers';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="clickpesa-control-numbers">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <div class="btn-group">
            <?= Html::a('Automatic Payout', ['payouts'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
            <?= Html::a('Settings', ['settings'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
        </div>
    </div>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h5">Create Control Number</h2>
            <?= Html::beginForm(['create-control-number'], 'post', ['class' => 'row g-2 align-items-end']) ?>
                <div class="col-md-2">
                    <label class="form-label">Order ID</label>
                    <?= Html::textInput('order_id', '', ['class' => 'form-control', 'required' => true]) ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Amount (TZS)</label>
                    <?= Html::textInput('amount', '', ['class' => 'form-control', 'type' => 'number', 'step' => '0.01', 'min' => '1', 'required' => true]) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Description</label>
                    <?= Html::textInput('description', '', ['class' => 'form-control', 'required' => true]) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Payment mode</label>
                    <?= Html::dropDownList('payment_mode', 'EXACT', [
                        'EXACT' => 'EXACT',
                        'ALLOW_PARTIAL_AND_OVER_PAYMENT' => 'ALLOW_PARTIAL_AND_OVER_PAYMENT',
                    ], ['class' => 'form-select']) ?>
                </div>
                <div class="col-md-2">
                    <?= Html::submitButton('Create Control Number', ['class' => 'btn btn-primary w-100']) ?>
                </div>
            <?= Html::endForm() ?>
        </div>
    </div>

    <?= GridView::widget([
        'dataProvider' => $provider,
        'tableOptions' => ['class' => 'table table-striped table-sm align-middle'],
        'columns' => [
            'id',
            'order_id',
            [
                'attribute' => 'customer_name',
                'label' => 'Customer',
            ],
            [
                'attribute' => 'control_number',
                'format' => 'raw',
                'value' => static function ($model) {
                    if (!$model->control_number) {
                        return '—';
                    }
                    $cn = Html::encode($model->control_number);

                    return $cn . ' '
                        . Html::button('Copy', [
                            'class' => 'btn btn-outline-secondary btn-sm ms-1 copy-cn',
                            'data-cn' => $model->control_number,
                            'type' => 'button',
                        ]);
                },
            ],
            [
                'attribute' => 'order_reference',
                'label' => 'Reference',
            ],
            [
                'attribute' => 'expected_amount',
                'label' => 'Expected',
                'value' => static fn($m) => number_format((float) ($m->expected_amount ?: $m->amount), 2),
            ],
            [
                'attribute' => 'received_amount',
                'label' => 'Received',
                'value' => static fn($m) => $m->received_amount !== null
                    ? number_format((float) $m->received_amount, 2)
                    : '—',
            ],
            'payment_status',
            [
                'attribute' => 'created_at',
                'label' => 'Created',
                'value' => static fn($m) => $m->created_at ? date('Y-m-d H:i', (int) $m->created_at) : '—',
            ],
            [
                'attribute' => 'paid_at',
                'label' => 'Paid',
                'value' => static fn($m) => $m->paid_at ? date('Y-m-d H:i', (int) $m->paid_at) : '—',
            ],
        ],
    ]) ?>
</div>
<?php
$this->registerJs(<<<'JS'
document.querySelectorAll('.copy-cn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var v = btn.getAttribute('data-cn') || '';
    if (navigator.clipboard) {
      navigator.clipboard.writeText(v);
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = 'Copy'; }, 1200);
    }
  });
});
JS);
?>
