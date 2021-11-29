<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\controllers;

use Yii;
use humhub\components\Controller;

class MapController extends Controller {
    public function actionIndex() {
        $settings = Yii::$app->getModule('usermap')->settings;

        return $this->render('index', [
            'mapWidgetLocation' => $settings->get('map_widget_location')
        ]);
    }
}
