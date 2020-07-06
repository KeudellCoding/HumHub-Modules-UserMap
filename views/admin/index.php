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
use humhub\modules\user\widgets\UserPickerField;

?>

<div class="panel panel-default">
    <div class="panel-heading">User Location Map configuration</div>
    <div class="panel-body">

        <?php $form = ActiveForm::begin(); ?>

        <?= $form->field($model, 'google_geocoding_api_key'); ?>

        <hr />

        <label class="control-label">Last cached failed request</label>
        <pre><?= $last_error === false ? 'No failed request detected.' : var_dump($last_error); ?></pre>

        <hr />

        <?= Html::submitButton("Save", ['class' => 'btn btn-primary', 'data-ui-loader' => '']); ?>
        <a class="btn btn-default" href="<?= Url::to(['/admin/module']); ?>">Back to modules</a>
        <!--<a class="btn btn-warning" href="<?= ""//Url::to(['/usermap/admin/update']); ?>" data-ui-loader="">Pull module update</a>-->

        <?php ActiveForm::end(); ?>
    </div>
</div>