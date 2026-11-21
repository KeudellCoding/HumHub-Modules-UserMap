<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\helpers\Html;
use humhub\modules\usermap\assets\MapAssetBundle;
use humhub\widgets\PanelMenu;

/** @var humhub\components\View $this */
/** @var string $id */
/** @var string $height */
/** @var string|null $link */
/** @var bool $showAsPanel */
/** @var bool $hasQueuedLookups */
/** @var array $mapOptions */

MapAssetBundle::register($this);
$previewLink = $link ? Html::a('', $link, [
    'class' => 'usermap-preview-link',
    'aria-label' => Yii::t('UsermapModule.base', 'Open map'),
]) : '';
?>

<?php if ($showAsPanel): ?>
    <div class="panel" id="<?= Html::encode($id . '-panel') ?>">
        <div class="panel-heading">
            <i class="fa fa-map-marker" aria-hidden="true"></i>
            <?= Html::encode(Yii::t('UsermapModule.base', 'User Location Map')) ?>
            <?= PanelMenu::widget(['collapseId' => $id . '-collapse']) ?>
        </div>
        <div class="panel-body p-0 collapse" id="<?= Html::encode($id . '-collapse') ?>">
<?php endif; ?>

<?= Html::beginTag('div', [
    'id' => $id,
    'class' => 'usermap-widget',
    'data' => [
        'ui-widget' => 'usermap.UserMap',
        'ui-init' => true,
        'map-options' => $mapOptions,
    ],
]) ?>
    <?= Html::tag('div', $previewLink, [
        'class' => 'usermap-map',
        'style' => ['height' => $height],
        'aria-label' => Yii::t('UsermapModule.base', 'User Location Map'),
    ]) ?>
<?php if ($hasQueuedLookups): ?>
    <div class="usermap-pending" role="status" tabindex="0"
       data-error="<?= Html::encode(Yii::t('UsermapModule.base', 'Map updates could not be loaded.')) ?>">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
        <span class="usermap-status-text"><?= Html::encode(Yii::t('UsermapModule.base', 'More locations are being looked up.')) ?></span>
    </div>
<?php endif; ?>

<?= Html::endTag('div') ?>

<?php if ($showAsPanel): ?>
        </div>
    </div>
<?php endif; ?>
