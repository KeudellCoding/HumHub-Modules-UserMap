<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap;

use Yii;
use yii\helpers\Url;
use yii\web\JsonParser;

class Events {
    public static function onDirectoryMenuInit($event) {
        try {
            $currentIdentity = Yii::$app->user->identity;
            if ($currentIdentity === null) {
                return;
            }
            
            $event->sender->addItem([
                'label' => 'Usermap',
                'url' => Url::to(['/usermap/map']),
                'group' => 'directory',
                'htmlOptions' => [],
                'icon' => '<i class="fa fa-map-marker"></i>',
                'isActive' => (Yii::$app->controller->module
                    && Yii::$app->controller->module->id === 'usermap'
                    && Yii::$app->controller->id === 'map'),
                'sortOrder' => 400,
            ]);
        } catch (\Throwable $e) {
            Yii::error($e);
        }
    }
}
