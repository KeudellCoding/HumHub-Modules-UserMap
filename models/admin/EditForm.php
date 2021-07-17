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
    public $geocoding_provider;
    public $geocoding_api_key;

    /**
     * @inheritdocs
     */
    public function rules() {
        return [
            [['geocoding_provider'], 'in', 'range' => array_keys(static::getProviders())],
            [['geocoding_api_key'], 'required'],
        ];
    }

    /**
     * @inheritdocs
     */
    public function init() {
        $settings = Yii::$app->getModule('usermap')->settings;
        $this->geocoding_provider = $settings->get('geocoding_provider');
        $this->geocoding_api_key = $settings->get('geocoding_api_key');
    }

    /**
     * Saves the given form settings.
     */
    public function save() {
        $settings = Yii::$app->getModule('usermap')->settings;
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
}
