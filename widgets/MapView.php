<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\widgets;

use Yii;
use yii\helpers\Url;
use humhub\components\Widget;
use humhub\modules\user\models\User;
use humhub\modules\usermap\models\admin\EditForm;

class MapView extends Widget {

    /**
     * Height of the Widget (css Values)
     * 
     * @var string
     */
    public $height = "20em";

    /**
     * Show map as panel
     * 
     * @var bool
     */
    public $showAsPanel = false;

    /**
     * Link that is navigated to when the map is clicked.
     * 
     * @var string
     */
    public $link = null;

    public function run() {
        $settings = Yii::$app->getModule('usermap')->settings;

        return $this->render(
            'mapView',
            [
                'height' => $this->height,
                'user_data' => $this->getAllUsers(),
                'link' => $this->link,
                'showAsPanel' => $this->showAsPanel,
                'osmTileServer' => $settings->get('osm_tile_server', EditForm::DEFAULT_TILE_SERVER),
                'mapCenter' => [
                    'latitude' => $settings->get('osm_map_center_latitude', 51.0951),
                    'longitude' => $settings->get('osm_map_center_longitude', 10.2714),
                    'zoom' => $settings->get('osm_map_center_zoom', 5)
                ]
            ]
        );
    }

    private function getAllUsers() {
        /** @var Module $module */
        $module = Yii::$app->getModule('usermap');

        $formatedUsers = [];
        foreach (User::findAll(['status' => User::STATUS_ENABLED]) as $user) {
            if ($module->showOnMapCallback !== null) {
                if (call_user_func($module->showOnMapCallback, $user) !== true) {
                    continue;
                }
            }

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
                'coords' => $this->getCoordinates($user)
            ];
        }
        return $formatedUsers;
    }

    private function getFormatedAddress(User $user) {
        /** @var Module $module */
        $module = Yii::$app->getModule('usermap');
        if ($module->getFormatedAddressCallback !== null) {
            return call_user_func($module->getFormatedAddressCallback, $user);
        }

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

    private function getCoordinates(User $user) {
        /** @var Module $module */
        $module = Yii::$app->getModule('usermap');
        if ($module->getCoordinatesCallback !== null) {
            return call_user_func($module->getCoordinatesCallback, $user);
        }

        $formatedAddress = $this->getFormatedAddress($user);
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
