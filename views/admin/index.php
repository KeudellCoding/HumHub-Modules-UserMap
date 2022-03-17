<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use yii\helpers\Html;
use yii\helpers\Url;
use humhub\widgets\ActiveForm;
use humhub\modules\usermap\models\admin\EditForm;

?>

<div class="panel panel-default">
    <div class="panel-heading">User Location Map configuration</div>
    <div class="panel-body">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->field($model, 'map_widget_location')->dropDownList(EditForm::getWidgetLocations()); ?>
        <?= $form->field($model, 'map_widget_location_sort_order')->input('number', ['min' => 0]); ?>

        <hr />

        <?= $form->field($model, 'osm_tile_server'); ?>

        <div class="row">
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_latitude'); ?>
            </div>
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_longitude'); ?>
            </div>
            <div class="col-md-4">
                <?= $form->field($model, 'osm_map_center_zoom'); ?>
            </div>
        </div>

        <hr />

        <?= $form->field($model, 'geocoding_provider')->dropDownList(EditForm::getProviders()); ?>
        <?= $form->field($model, 'geocoding_api_key'); ?>

        <hr />

        <label class="control-label">Last cached failed request</label>
        <pre><?= $last_error === false ? 'No failed request in the cache.' : var_dump($last_error); ?></pre>

        <hr />

        <?= Html::submitButton("Save", ['class' => 'btn btn-primary', 'data-ui-loader' => '']); ?>
        <a class="btn btn-default" href="<?= Url::to(['/admin/module']); ?>">Back to modules</a>
        <a>This module is now available in the Marketplace.</a>
        
        <hr />

        <span>
            Additional modifications can be made. HumHub offers the possibility to edit properties and methods of modules.
            Details can be found <a href="https://docs.humhub.org/docs/admin/advanced-configuration/#module-configurations" target="_blank" rel="noopener noreferrer">here</a>.<br />
            Details on the possible configurations can be found in the Module.php file.
        </span>

        <hr />

        <span>If you like this plugin, I would be very happy about a <a href="https://ko-fi.com/KeudellCoding" target="_blank" rel="noopener noreferrer">Ko-fi</a> :)</span>

        <?php ActiveForm::end(); ?>
    </div>
</div>