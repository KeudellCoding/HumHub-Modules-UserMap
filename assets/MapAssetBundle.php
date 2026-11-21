<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\assets;

use humhub\assets\CoreApiAsset;
use humhub\components\assets\AssetBundle;
use yii\web\YiiAsset;

class MapAssetBundle extends AssetBundle
{
    public $sourcePath = '@usermap/resources';

    public $forceCopy = true;

    public $css = [
        'leaflet/leaflet.css',
        'leaflet.markercluster/MarkerCluster.css',
        'leaflet.markercluster/MarkerCluster.Default.css',
        'usermap.css',
    ];

    public $js = [
        'leaflet/leaflet.js',
        'leaflet.markercluster/leaflet.markercluster.js',
        'humhub.usermap.js',
    ];

    public $depends = [
        YiiAsset::class,
        CoreApiAsset::class,
    ];
}
