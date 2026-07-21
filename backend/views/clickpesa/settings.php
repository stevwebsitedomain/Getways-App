<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\ClickPesaSetting $settings */
/** @var string $maskedDestination */

use common\models\ClickPesaSetting;
use yii\helpers\Html;

$this->title = 'Automatic Payout Settings';
$this->params['breadcrumbs'][] = ['label' => 'ClickPesa', 'url' => ['control-numbers']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="clickpesa-settings">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('Back to payouts', ['payouts'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>

    <div class="alert alert-warning">
        Once enabled, successful verified collections may automatically trigger payouts to the configured destination.
        Default destination: <code>2557******92</code> (<?= Html::encode($maskedDestination) ?>).
        Keep automatic payout <strong>OFF</strong> until tests pass.
    </div>

    <?= Html::beginForm(['save-settings'], 'post', ['class' => 'card border-0 shadow-sm']) ?>
    <div class="card-body row g-3">
        <div class="col-md-6">
            <div class="form-check form-switch">
                <?= Html::checkbox('auto_payout_enabled', (bool) $settings->auto_payout_enabled, [
                    'class' => 'form-check-input',
                    'id' => 'auto_payout_enabled',
                    'value' => '1',
                ]) ?>
                <label class="form-check-label" for="auto_payout_enabled">Enable automatic payout</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check form-switch">
                <?= Html::checkbox('require_manual_approval', (bool) $settings->require_manual_approval, [
                    'class' => 'form-check-input',
                    'id' => 'require_manual_approval',
                    'value' => '1',
                ]) ?>
                <label class="form-check-label" for="require_manual_approval">Require manual approval before sending</label>
            </div>
        </div>

        <div class="col-md-4">
            <label class="form-label">Mode</label>
            <?= Html::dropDownList('mode', $settings->mode ?: ClickPesaSetting::MODE_TEST, [
                ClickPesaSetting::MODE_TEST => 'TEST',
                ClickPesaSetting::MODE_MANUAL_APPROVAL => 'MANUAL APPROVAL',
                ClickPesaSetting::MODE_LIVE_AUTO => 'LIVE AUTO',
            ], ['class' => 'form-select']) ?>
        </div>

        <div class="col-md-4">
            <label class="form-label">Destination type</label>
            <?= Html::dropDownList('destination_type', $settings->destination_type, [
                ClickPesaSetting::DESTINATION_MOBILE => 'MOBILE_MONEY',
                ClickPesaSetting::DESTINATION_BANK => 'BANK',
            ], ['class' => 'form-select', 'id' => 'destination_type']) ?>
        </div>

        <div class="col-md-4">
            <label class="form-label">Current destination (masked)</label>
            <input type="text" class="form-control" value="<?= Html::encode($maskedDestination) ?>" readonly>
        </div>

        <div class="col-md-4 mobile-fields">
            <label class="form-label">Mobile money provider</label>
            <?= Html::textInput('mobile_provider', $settings->mobile_provider, [
                'class' => 'form-control',
                'placeholder' => 'M-Pesa / Airtel Money / Tigo Pesa',
            ]) ?>
        </div>

        <div class="col-md-4 mobile-fields">
            <label class="form-label">New mobile money number</label>
            <?= Html::textInput('destination_phone', '', [
                'class' => 'form-control',
                'placeholder' => '+255715296092',
                'autocomplete' => 'off',
            ]) ?>
            <div class="form-text">Leave blank to keep current number.</div>
        </div>

        <div class="col-md-4 bank-fields d-none">
            <label class="form-label">Bank name</label>
            <?= Html::textInput('bank_name', $settings->bank_name, ['class' => 'form-control']) ?>
        </div>
        <div class="col-md-4 bank-fields d-none">
            <label class="form-label">Account name</label>
            <?= Html::textInput('bank_account_name', $settings->bank_account_name, ['class' => 'form-control']) ?>
        </div>
        <div class="col-md-4 bank-fields d-none">
            <label class="form-label">Account number</label>
            <?= Html::textInput('bank_account_number', '', [
                'class' => 'form-control',
                'autocomplete' => 'off',
                'placeholder' => 'Leave blank to keep',
            ]) ?>
        </div>
        <div class="col-md-4 bank-fields d-none">
            <label class="form-label">BIC / SWIFT</label>
            <?= Html::textInput('bank_bic_swift', $settings->bank_bic_swift, [
                'class' => 'form-control',
                'placeholder' => 'Optional',
            ]) ?>
        </div>

        <div class="col-md-3">
            <label class="form-label">Payout %</label>
            <?= Html::textInput('payout_percentage', $settings->payout_percentage, [
                'class' => 'form-control',
                'type' => 'number',
                'step' => '0.01',
                'min' => '0',
                'max' => '100',
            ]) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Minimum amount</label>
            <?= Html::textInput('minimum_amount', $settings->minimum_amount, [
                'class' => 'form-control',
                'type' => 'number',
                'step' => '0.01',
                'min' => '0',
            ]) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Daily limit (0 = unlimited)</label>
            <?= Html::textInput('daily_limit', $settings->daily_limit, [
                'class' => 'form-control',
                'type' => 'number',
                'step' => '0.01',
                'min' => '0',
            ]) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Delay (seconds)</label>
            <?= Html::textInput('delay_seconds', $settings->delay_seconds, [
                'class' => 'form-control',
                'type' => 'number',
                'min' => '0',
            ]) ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">Admin password (required to enable auto payout or change destination)</label>
            <?= Html::passwordInput('admin_password', '', [
                'class' => 'form-control',
                'autocomplete' => 'current-password',
            ]) ?>
        </div>

        <div class="col-12">
            <?= Html::submitButton('Save settings', ['class' => 'btn btn-primary']) ?>
        </div>
    </div>
    <?= Html::endForm() ?>
</div>
<?php
$this->registerJs(<<<'JS'
function toggleDest() {
  var t = document.getElementById('destination_type').value;
  document.querySelectorAll('.bank-fields').forEach(function (el) {
    el.classList.toggle('d-none', t !== 'BANK');
  });
  document.querySelectorAll('.mobile-fields').forEach(function (el) {
    el.classList.toggle('d-none', t === 'BANK');
  });
}
document.getElementById('destination_type').addEventListener('change', toggleDest);
toggleDest();
JS);
?>
