<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\usermap\components\Geocoder;
use humhub\modules\usermap\models\admin\EditForm;
use Yii;

class AdminController extends Controller
{
    public function actionIndex()
    {
        $form = new EditForm();
        $request = Yii::$app->request;
        if ($request->isPost && $form->load($request->post()) && $form->save()) {
            $this->view->saved();
            return $this->redirect(['/usermap/admin/index']);
        }

        return $this->render('index', [
            'model' => $form,
            'last_error' => Geocoder::getLastError(),
        ]);
    }
}
