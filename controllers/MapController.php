<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\controllers;

use humhub\components\Controller;
use humhub\modules\user\models\User;
use humhub\modules\usermap\components\MapMembers;
use Yii;
use yii\web\ForbiddenHttpException;

class MapController extends Controller
{
    public function actionIndex()
    {
        $this->requireViewer();
        return $this->render('index');
    }

    public function actionLookup()
    {
        $this->forcePostRequest();
        $viewer = $this->requireViewer();
        Yii::$app->response->headers->set('Cache-Control', 'private, no-store');
        // Geocoding can wait on a provider. Release this viewer's PHP session for other pages.
        Yii::$app->session->close();
        $lookup = $this->takeLookup($viewer, Yii::$app->request->post('token'));
        return $this->asJson(['users' => $lookup === null ? [] : (new MapMembers())->resolve(
            $viewer,
            $lookup['memberIds'],
            $lookup['lookupId'],
            $lookup['preview'],
        )]);
    }

    public function actionUpdates()
    {
        $this->forcePostRequest();
        $viewer = $this->requireViewer();
        Yii::$app->response->headers->set('Cache-Control', 'private, no-store');
        Yii::$app->session->close();
        $token = Yii::$app->request->post('token');
        return $this->asJson(is_string($token) && strlen($token) === 32
            ? (new MapMembers())->updates($viewer, $token)
            : ['users' => [], 'next' => null]);
    }

    private function requireViewer(): User
    {
        $viewer = Yii::$app->user->getIdentity();
        if (!$viewer instanceof User) {
            throw new ForbiddenHttpException(Yii::t('UsermapModule.base', 'You are not allowed to view this map.'));
        }
        return $viewer;
    }

    private function takeLookup(User $viewer, $token): ?array
    {
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{32}\z/', $token)) {
            return null;
        }
        $lock = 'usermap.deferred.' . $token;
        if (!Yii::$app->mutex->acquire($lock, 0)) {
            return null;
        }
        try {
            $key = ['usermap.deferred', (int) $viewer->id, $token];
            $lookup = Yii::$app->cache->get($key);
            return is_array($lookup) && Yii::$app->cache->delete($key) ? $lookup : null;
        } finally {
            Yii::$app->mutex->release($lock);
        }
    }
}
