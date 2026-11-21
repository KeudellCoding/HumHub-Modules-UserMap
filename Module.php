<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap;

use humhub\modules\usermap\components\GeocodingSettings;
use yii\helpers\Url;

class Module extends \humhub\components\Module
{
    /**
     * @var callable a callback that returns true if the user is to be displayed on the map, false otherwise.
     * The only parameter is the user. The result is not cached.
     */
    public $showOnMapCallback = null;

    /**
     * @var callable a callback that returns the formated address as a string of a user or null if there is no address.
     * The only parameter is the user. The result is not cached.
     */
    public $getFormatedAddressCallback = null;

    /**
     * @var callable a callback that returns null or the coordinates of a user as an array: ['latitude' => 0, 'longitude' => 0].
     * The only parameter is the user. The result is not cached.
     */
    public $getCoordinatesCallback = null;

    /**
     * @inheritdoc
     */
    public $resourcesPath = 'resources';

    /**
     * Keeps the former directory menu setting usable after upgrading.
     */
    public function getMapWidgetLocation(): string
    {
        $location = $this->settings->get('map_widget_location', 'top_menu');
        return in_array($location, ['top_menu', 'dashboard_sidebar'], true) ? $location : 'top_menu';
    }

    /** @return array{provider: string, apiKey: string} */
    public function getGeocodingConfiguration(): array
    {
        return (new GeocodingSettings($this->settings))->read();
    }

    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/usermap/admin/index']);
    }

    public function getHomepageUrl(): ?string
    {
        return $this->getModuleInfo()['homepage'] ?? null;
    }
}
