<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\assets;

use yii\web\AssetBundle;

class MapAssetBundle extends AssetBundle {
    /**
     * v1.5 compatibility defer script loading
     *
     * Migrate to HumHub AssetBundle once minVersion is >=1.5
     *
     * @var bool
     */
    public $defer = true;

    public $sourcePath = '@usermap/resources';

    public $css = [
        'leaflet/leaflet.css',
        'leaflet.markercluster/MarkerCluster.css',
        'leaflet.markercluster/MarkerCluster.Default.css',
    ];
    
    public $js = [
        'leaflet/leaflet.js',
        'leaflet.markercluster/leaflet.markercluster.js'
    ];
}
