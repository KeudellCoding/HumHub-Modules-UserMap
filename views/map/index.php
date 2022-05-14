<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\modules\usermap\widgets\MapView;
?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="panel">
                <div class="panel-heading"><i class="fa fa-map-marker"></i> <span><strong>User</strong> Location Map</span></div>
                <div class="panel-body">
                <?= MapView::widget(['height' => '80vh']) ?>
                </div>
            </div>
        </div>
    </div>
</div>