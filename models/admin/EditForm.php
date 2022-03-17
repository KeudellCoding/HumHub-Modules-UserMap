<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\models\admin;

use Yii;
use yii\base\Model;

class EditForm extends Model {
    const DEFAULT_TILE_SERVER = 'https://{s}.tile.osm.org/{z}/{x}/{y}.png';

    public $map_widget_location;
    public $map_widget_location_sort_order;
    public $osm_tile_server;
    public $osm_map_center_latitude;
    public $osm_map_center_longitude;
    public $osm_map_center_zoom;
    public $geocoding_provider;
    public $geocoding_api_key;

    /**
     * @inheritdocs
     */
    public function rules() {
        return [
            [['map_widget_location'], 'in', 'range' => array_keys(static::getWidgetLocations())],
            [['map_widget_location_sort_order', 'osm_map_center_zoom'], 'number', 'min' => 0],
            ['osm_map_center_latitude', 'double', 'min' => -90, 'max' => 90],
            ['osm_map_center_longitude', 'double', 'min' => -180, 'max' => 180],
            [['geocoding_provider'], 'in', 'range' => array_keys(static::getProviders())],
            [['geocoding_api_key', 'map_widget_location_sort_order'], 'required'],
        ];
    }

    /**
     * @inheritdocs
     */
    public function init() {
        $settings = Yii::$app->getModule('usermap')->settings;

        $oldApiKey = $settings->get('google_geocoding_api_key');
        if ($oldApiKey !== null) {
            $settings->set('geocoding_provider', 'google');
            $settings->set('geocoding_api_key', $oldApiKey);
            $settings->delete('google_geocoding_api_key');
        }

        $this->map_widget_location = $settings->get('map_widget_location');
        $this->map_widget_location_sort_order = $settings->get('map_widget_location_sort_order', 400);
        $this->osm_tile_server = $settings->get('osm_tile_server', self::DEFAULT_TILE_SERVER);
        $this->osm_map_center_latitude = $settings->get('osm_map_center_latitude', 51.0951);
        $this->osm_map_center_longitude = $settings->get('osm_map_center_longitude', 10.2714);
        $this->osm_map_center_zoom = $settings->get('osm_map_center_zoom', 5);
        $this->geocoding_provider = $settings->get('geocoding_provider');
        $this->geocoding_api_key = $settings->get('geocoding_api_key');
    }

    /**
     * Saves the given form settings.
     */
    public function save() {
        $settings = Yii::$app->getModule('usermap')->settings;
        $settings->set('map_widget_location', $this->map_widget_location);
        $settings->set('map_widget_location_sort_order', $this->map_widget_location_sort_order);
        $settings->set('osm_tile_server', $this->osm_tile_server);
        $settings->set('osm_map_center_latitude', $this->osm_map_center_latitude);
        $settings->set('osm_map_center_longitude', $this->osm_map_center_longitude);
        $settings->set('osm_map_center_zoom', $this->osm_map_center_zoom);
        $settings->set('geocoding_provider', $this->geocoding_provider);
        $settings->set('geocoding_api_key', $this->geocoding_api_key);

        return true;
    }

    /**
     * @inheritdocs
     */
    public function attributeLabels() {
        return [
            'osm_map_center_latitude' => 'Default Latitude',
            'osm_map_center_longitude' => 'Default Longitude',
            'osm_map_center_zoom' => 'Default Zoom Level'
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints() {
        $result = [
            'osm_tile_server' => 'Here you can specify your own tile server. You can find the format <a href="https://leafletjs.com/SlavaUkraini/reference.html#tilelayer" target="_blank" rel="noopener noreferrer">here</a>.',
            'osm_map_center_latitude' => 'Please enter a decimal number with a dot as separator',
            'osm_map_center_longitude' => 'Please enter a decimal number with a dot as separator'
        ];

        return $result;
    }

    public static function getProviders() {
        return [
            'google' => 'Google',
            'mapbox' => 'Mapbox',
            'here' => 'Here.com'
        ];
    }

    public static function getWidgetLocations() {
        $result = [
            'dashboard_sidebar' => 'Dashboard sidebar',
            'top_menu' => 'Top menu'
        ];

        if (version_compare(Yii::$app->version, '1.9.0', 'lt')) {
            $result['directory_menu'] = 'Directory menu (Not available as of HumHub version 1.9)';
        }

        return $result;
    }
}
