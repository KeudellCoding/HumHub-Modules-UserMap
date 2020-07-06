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
use humhub\components\Controller;
use humhub\modules\user\models\User;

class MapController extends Controller {
    public function actionIndex() {
        return $this->render('index', ['user_data' => $this->getAllUsers()]);
    }

    private function getAllUsers() {
        $formatedUsers = [];
        foreach (User::findAll(['status' => User::STATUS_ENABLED]) as $user) {
            $formatedAddress = $this->getFormatedAddress($user);

            $formatedUsers[] = [
                'id' => $user->id,
                'guid' => $user->guid,
                'username' => $user->username,
                'firstname' => $user->profile->firstname,
                'lastname' => $user->profile->lastname,
                'street' => $user->profile->street,
                'zip' => $user->profile->zip,
                'city' => $user->profile->city,
                'country' => $user->profile->country,
                'profileurl' => Url::to(['/user/profile', 'cguid' => $user->guid]),
                'formatedaddress' => $formatedAddress,
                'coords' => $this->getCoordinates($formatedAddress)
            ];
        }
        return $formatedUsers;
    }

    private function getFormatedAddress(User $user) {
        if(!empty($user->profile->street) && !empty($user->profile->zip) && !empty($user->profile->city)){
            return $user->profile->street.', '.$user->profile->zip.' '.$user->profile->city;
        }
        else {
            return null;
        }
    }

    private function getCoordinates(string $formatedAddress) {
        if (empty($formatedAddress)) {
            return null;
        }

        $cacheKey = 'usermap.cache.'.base64_encode($formatedAddress);
        $coords = Yii::$app->cache->get($cacheKey);
        if ($coords === false) {
            $settings = Yii::$app->getModule('usermap')->settings;
            $apiKey = $settings->get('google_geocoding_api_key');
            if (empty($apiKey)) {
                return null;
            }

            $rawGeocodingResponse = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($formatedAddress).'&key='.$apiKey);
            if(!empty($rawGeocodingResponse)){
                $geocodingResponse = json_decode($rawGeocodingResponse, true);
                if($geocodingResponse['status'] === 'OK' && count($geocodingResponse['results']) >= 1){
                    $coords = [
                        'latitude' => $geocodingResponse['results'][0]['geometry']['location']['lat'],
                        'longitude' => $geocodingResponse['results'][0]['geometry']['location']['lng']
                    ];

                    Yii::$app->cache->set($cacheKey, $coords, 0);

                    return $coords;
                }
            }
        }
        else {
            return $coords;
        }
    }
}
