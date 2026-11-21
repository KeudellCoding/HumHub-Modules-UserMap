<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\helpers\Html;
use humhub\modules\usermap\models\admin\EditForm;
use humhub\widgets\bootstrap\Button;
use humhub\widgets\form\ActiveForm;
use yii\helpers\Json;

/** @var humhub\components\View $this */
/** @var EditForm $model */
/** @var array|false $last_error */

$homepageUrl = Yii::$app->getModule('usermap')->getHomepageUrl();
?>

<div class="panel panel-default">
    <div class="panel-heading"><?= Html::encode(Yii::t('UsermapModule.base', 'User Location Map configuration')) ?></div>
    <div class="panel-body">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->errorSummary($model) ?>
        <?= $form->field($model, 'map_widget_location')->dropDownList(EditForm::getWidgetLocations()) ?>
        <?= $form->field($model, 'map_widget_location_sort_order')->input('number', ['min' => 0, 'step' => 1]) ?>

        <hr>

        <?= $form->field($model, 'osm_tile_server')->textInput() ?>

        <div class="row">
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_latitude')->input('number', ['min' => -90, 'max' => 90, 'step' => 'any']) ?>
            </div>
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_longitude')->input('number', ['min' => -180, 'max' => 180, 'step' => 'any']) ?>
            </div>
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_zoom')->input('number', ['min' => 0, 'max' => 19, 'step' => 1]) ?>
            </div>
        </div>

        <hr>

        <?= $form->field($model, 'geocoding_provider')->dropDownList(EditForm::getProviders(), ['disabled' => $model->isProviderFixed()]) ?>
        <?= $form->field($model, 'geocoding_api_key')->passwordInput([
            'value' => '',
            'placeholder' => $model->hasSavedApiKey() ? '********' : null,
            'autocomplete' => 'new-password',
            'disabled' => $model->isProviderFixed() && $model->isApiKeyFixed(),
        ]) ?>
        <?= $form->field($model, 'geocoding_use_street')->checkbox() ?>

        <hr>

        <p class="fw-semibold"><?= Html::encode(Yii::t('UsermapModule.base', 'Last failed geocoding request')) ?></p>
        <pre><?= Html::encode($last_error === false
            ? Yii::t('UsermapModule.base', 'No failed request in the cache.')
            : Json::encode($last_error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

        <hr>

        <?= Button::save()->submit() ?>
        <?= Html::a(Html::encode(Yii::t('UsermapModule.base', 'Back to modules')), ['/admin/module'], ['class' => 'btn btn-secondary']) ?>

        <?php ActiveForm::end(); ?>

        <?php if ($homepageUrl): ?>
            <hr />

            <span><?= Yii::t('UsermapModule.base', 'Found a bug or have a feature request? Support the development on {github} :)', [
                'github' => Html::a('GitHub', $homepageUrl, [
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                ]),
            ]) ?></span>
        <?php endif; ?>
    </div>
</div>
