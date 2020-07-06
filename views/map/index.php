<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use yii\helpers\Url;
use humhub\assets\JqueryKnobAsset;
use humhub\widgets\AjaxButton;
use humhub\modules\directory\widgets\Menu;
use humhub\modules\usermap\assets\MapAssetBundle;

JqueryKnobAsset::register($this);
MapAssetBundle::register($this);
?>

<div class="container">
    <div class="row">
        <div class="col-md-2">
            <?= Menu::widget(); ?>
        </div>
        <div class="col-md-10">
            <div id="user-main-map" style="height: 80vh;"></div>

            <script>
                $(document).ready(function(){
                    var map = L.map('user-main-map').setView([51.0951, 10.2714], 5);
                    var markers = L.markerClusterGroup();
                
                    L.tileLayer('https://{s}.tile.osm.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="http://osm.org/copyright">OpenStreetMap</a> contributors'
                    }).addTo(map);
                    
                    var allUsers = <?=json_encode($user_data);?>;
                    
                    $.each(allUsers, function(i, user){
                        if (user.coords && user.coords.latitude && user.coords.longitude) {
                            var marker = L.marker([user.coords.latitude, user.coords.longitude]);
                            marker.bindPopup('<a href="'+user.profileurl+'"><b>'+user.displayname+'</b></a><br/>'+user.formatedaddress+'<br/>');
                            markers.addLayer(marker);
                        }
                    });
                    
                    map.addLayer(markers);
                });
            </script>
        </div>
    </div>
</div>