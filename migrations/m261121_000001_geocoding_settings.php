<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\components\SettingsManager;
use humhub\modules\usermap\components\GeocodingSettings;
use yii\db\Migration;

class m261121_000001_geocoding_settings extends Migration
{
    public function up()
    {
        $settings = new SettingsManager(['moduleId' => 'usermap']);
        try {
            $this->db->transaction(static fn() => (new GeocodingSettings($settings))->migrate());
        } finally {
            $settings->reload();
        }
        Yii::$app->cache->delete('usermap.error.cache.lasterrors');
    }
}
