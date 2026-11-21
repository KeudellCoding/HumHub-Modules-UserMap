<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\helpers\Html;
use humhub\modules\usermap\widgets\MapView;

/** @var humhub\components\View $this */
$this->title = Yii::t('UsermapModule.base', 'User Location Map');
?>

<div class="container">
    <div class="row">
        <div class="col-12">
            <div class="panel">
                <div class="panel-heading">
                    <i class="fa fa-map-marker" aria-hidden="true"></i>
                    <?= Html::encode($this->title) ?>
                </div>
                <div class="panel-body">
                    <?= MapView::widget() ?>
                </div>
            </div>
        </div>
    </div>
</div>
