<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\libs\Html;
use humhub\widgets\PanelMenu;
use humhub\assets\JqueryKnobAsset;
use humhub\modules\usermap\assets\MapAssetBundle;

JqueryKnobAsset::register($this);
MapAssetBundle::register($this);
?>

<?php if ($showAsPanel) { ?>
    <div class="panel" id="usermap-map-view-snippet">
        
        <div class="panel-heading">
            <i class="fa fa-map-marker"></i> <span><strong>User</strong> Location Map</span>
            <?= PanelMenu::widget(['id' => 'usermap-map-view-snippet']); ?>
        </div>
        
        <div class="panel-body" style="padding:0px;">
<?php } ?>
            
            <div id="user-main-map-link" style="height: <?= $height ?>;">
                <div id="user-main-map" style="height: 100%;"></div>
            </div>

            <script <?= Html::nonce() ?>>
                $(document).ready(function(){
                    var map = L.map('user-main-map').setView([<?= $mapCenter['latitude'] ?>, <?= $mapCenter['longitude'] ?>], <?= $mapCenter['zoom'] ?>);
                    var markers = L.markerClusterGroup();
                
                    L.tileLayer('<?= $osmTileServer ?>', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
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

            <?php if (!empty($link)) { ?>
                <script <?= Html::nonce() ?>>
                    $(document).ready(function(){
                        $('#user-main-map-link').click(function(){
                            window.location.href = "<?= $link ?>";
                        });
                    });
                </script>

                <style>
                    #user-main-map-link {
                        cursor: pointer;
                    }

                    #user-main-map-link * {
                        pointer-events: none;
                    }
                </style>
            <?php } ?>

<?php if ($showAsPanel) { ?>
        </div>
    </div>
<?php } ?>            