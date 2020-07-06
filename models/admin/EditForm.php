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
    public $google_geocoding_api_key;

    /**
     * @inheritdocs
     */
    public function rules() {
        return [
            [['google_geocoding_api_key'], 'required'],
        ];
    }

    /**
     * @inheritdocs
     */
    public function init() {
        $settings = Yii::$app->getModule('usermap')->settings;
        $this->google_geocoding_api_key = $settings->get('google_geocoding_api_key');
    }

    /**
     * Saves the given form settings.
     */
    public function save() {
        $settings = Yii::$app->getModule('usermap')->settings;
        $settings->set('google_geocoding_api_key', $this->google_geocoding_api_key);

        return true;
    }
}
