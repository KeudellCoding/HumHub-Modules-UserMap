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
                'displayname' => $user->displayname,
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
        if (!empty($user->profile->street) && !empty($user->profile->zip) && !empty($user->profile->city)){
            $result = $user->profile->street.', '.$user->profile->zip.' '.$user->profile->city;
            
            if (!empty($user->profile->country)) {
                $result .= ', '.$user->profile->country;
            }
            
            return $result;
        }
        else {
            return null;
        }
    }

    private function getCoordinates($formatedAddress) {
        if (empty($formatedAddress)) {
            return null;
        }

        $cacheKey = 'usermap.cache.'.base64_encode($formatedAddress);
        $errorCacheKey = 'usermap.error.cache.lasterrors';

        try {
            $coords = Yii::$app->cache->get($cacheKey);
            if ($coords === false) {
                $coords = null;

                $settings = Yii::$app->getModule('usermap')->settings;

                $apiProvider = $settings->get('geocoding_provider');
                if (empty($apiProvider)) {
                    Yii::$app->cache->set($errorCacheKey, ['error_message' => 'No provider given']);
                    return null;
                }

                $apiKey = $settings->get('geocoding_api_key');
                if (empty($apiKey)) {
                    Yii::$app->cache->set($errorCacheKey, ['error_message' => 'API key empty']);
                    return null;
                }

                switch ($apiProvider) {
                    case 'google':
                        $rawGeocodingResponse = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($formatedAddress).'&key='.$apiKey);
                        if (!empty($rawGeocodingResponse)) {
                            $geocodingResponse = json_decode($rawGeocodingResponse, true);
                            if ($geocodingResponse['status'] === 'OK') {
                                if (count($geocodingResponse['results']) >= 1) {
                                    $coords = [
                                        'latitude' => $geocodingResponse['results'][0]['geometry']['location']['lat'],
                                        'longitude' => $geocodingResponse['results'][0]['geometry']['location']['lng']
                                    ];

                                    Yii::$app->cache->set($cacheKey, $coords, 0);
                                }
                                return $coords;
                            }
                            else {
                                Yii::$app->cache->set($errorCacheKey, $geocodingResponse);
                            }
                        }
                        else {
                            Yii::$app->cache->set($errorCacheKey, ['error_message' => 'Result empty']);
                        }
                        return null;

                    case 'mapbox':
                        $rawGeocodingResponse = file_get_contents('https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode($formatedAddress).'.json?access_token='.$apiKey.'&autocomplete=false&limit=1');
                        if (!empty($rawGeocodingResponse)) {
                            $geocodingResponse = json_decode($rawGeocodingResponse, true);
                            if (isset($geocodingResponse['features'])) {
                                if (count($geocodingResponse['features']) >= 1) {
                                    $coords = [
                                        'latitude' => $geocodingResponse['features'][0]['center'][1],
                                        'longitude' => $geocodingResponse['features'][0]['center'][0]
                                    ];

                                    Yii::$app->cache->set($cacheKey, $coords, 0);
                                }
                                return $coords;
                            }
                            else {
                                Yii::$app->cache->set($errorCacheKey, $geocodingResponse);
                            }
                        }
                        else {
                            Yii::$app->cache->set($errorCacheKey, ['error_message' => 'Result empty']);
                        }
                        return null;

                    case 'here':
                        $rawGeocodingResponse = file_get_contents('https://geocoder.ls.hereapi.com/6.2/geocode.json?searchtext='.urlencode($formatedAddress).'&apiKey='.$apiKey.'&maxresults=1');
                        if (!empty($rawGeocodingResponse)) {
                            $geocodingResponse = json_decode($rawGeocodingResponse, true);
                            if (isset($geocodingResponse['Response']) && isset($geocodingResponse['Response']['View'])) {
                                if (count($geocodingResponse['Response']['View']) >= 1) {
                                    $coords = [
                                        'latitude' => $geocodingResponse['Response']['View'][0]['Result'][0]['Location']['DisplayPosition']['Latitude'],
                                        'longitude' => $geocodingResponse['Response']['View'][0]['Result'][0]['Location']['DisplayPosition']['Longitude']
                                    ];

                                    Yii::$app->cache->set($cacheKey, $coords, 0);
                                }
                                return $coords;
                            }
                            else {
                                Yii::$app->cache->set($errorCacheKey, $geocodingResponse);
                            }
                        }
                        else {
                            Yii::$app->cache->set($errorCacheKey, ['error_message' => 'Result empty']);
                        }
                        return null;
                    
                    default:
                        Yii::$app->cache->set($errorCacheKey, ['error_message' => 'Provider not supported']);
                        return null;
                }
            }
            else {
                return $coords;
            }
        } catch (\Throwable $th) {
            Yii::$app->cache->set($errorCacheKey, ['error_message' => $th->getMessage()]);
            return null;
        } catch (\Exception $th) {
            Yii::$app->cache->set($errorCacheKey, ['error_message' => $th->getMessage()]);
            return null;
        }
        
    }
}
