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
    public $map_widget_location;
    public $map_widget_location_sort_order;
    public $geocoding_provider;
    public $geocoding_api_key;

    /**
     * @inheritdocs
     */
    public function rules() {
        return [
            [['map_widget_location'], 'in', 'range' => array_keys(static::getWidgetLocations())],
            [['map_widget_location_sort_order'], 'number', 'min' => 0],
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
        $settings->set('geocoding_provider', $this->geocoding_provider);
        $settings->set('geocoding_api_key', $this->geocoding_api_key);

        return true;
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
