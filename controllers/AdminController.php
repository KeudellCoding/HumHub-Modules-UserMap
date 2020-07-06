<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\controllers;

use Yii;
use yii\helpers\Url;
use humhub\components\Response;
use humhub\modules\admin\components\Controller;
use humhub\modules\usermap\models\admin\EditForm;

class AdminController extends Controller {
    public function actionIndex() {
        Yii::$app->response->format = Response::FORMAT_HTML;

        $form = new EditForm();
        
        if ($form->load(Yii::$app->request->post()) && $form->validate() && $form->save()) {
            $this->view->saved();
            return $this->redirect(Url::to(['/usermap/admin/index']));
        }

        $errorCacheKey = 'usermap.error.cache.lasterrors';
        $lastError = Yii::$app->cache->get($errorCacheKey);

        return $this->render('index', ['model' => $form, 'last_error' => $lastError]);
    }

    public function actionUpdate() {
        exec('git', $gitAvailable);
        if (!empty($gitAvailable)) {
            exec('cd '.__DIR__.' && cd .. && git pull origin master');
        }
        return $this->redirect(Url::to(['/usermap/admin/index']));
    }
}
