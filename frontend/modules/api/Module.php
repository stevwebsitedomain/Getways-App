<?php

declare(strict_types=1);

namespace frontend\modules\api;

use yii\base\Module as BaseModule;

/**
 * Frontend API module (ClickPesa and related JSON endpoints).
 */
class Module extends BaseModule
{
    public $controllerNamespace = 'frontend\modules\api\controllers';

    public function init(): void
    {
        parent::init();
        \Yii::$app->user->enableSession = false;
    }
}
