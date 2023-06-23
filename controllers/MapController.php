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
use yii\web\ForbiddenHttpException;

class MapController extends Controller {
    public function actionIndex() {
        if (Yii::$app->user->isGuest) {
            throw new ForbiddenHttpException('You\'re not allowed to enter this page.');
        }

        $settings = Yii::$app->getModule('usermap')->settings;

        return $this->render('index', [
            'mapWidgetLocation' => $settings->get('map_widget_location')
        ]);
    }
}
