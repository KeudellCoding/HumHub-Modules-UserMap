<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap;

use Yii;
use yii\helpers\Url;
use humhub\modules\usermap\widgets\MapView;

class Events {
    public static function onTopMenuInit($event) {
        try {
            $settings = Yii::$app->getModule('usermap')->settings;
            if ($settings->get('map_widget_location') !== 'top_menu') {
                return;
            }

            $currentIdentity = Yii::$app->user->identity;
            if ($currentIdentity === null || Yii::$app->user->isGuest) {
                return;
            }
            
            $event->sender->addItem([
                'label' => 'Usermap',
                'url' => Url::to(['/usermap/map']),
                'htmlOptions' => [],
                'icon' => '<i class="fa fa-map-marker"></i>',
                'isActive' => (Yii::$app->controller->module
                    && Yii::$app->controller->module->id === 'usermap'
                    && Yii::$app->controller->id === 'map'),
                'sortOrder' => $settings->get('map_widget_location_sort_order', 400),
            ]);
        } catch (\Throwable $e) {
            Yii::error($e);
        }
    }

    public static function onDirectoryMenuInit($event) {
        try {
            $settings = Yii::$app->getModule('usermap')->settings;
            if ($settings->get('map_widget_location') !== 'directory_menu') {
                return;
            }

            $currentIdentity = Yii::$app->user->identity;
            if ($currentIdentity === null || Yii::$app->user->isGuest) {
                return;
            }
            
            $event->sender->addItem([
                'label' => 'Usermap',
                'url' => Url::to(['/usermap/map']),
                'group' => 'directory',
                'htmlOptions' => [],
                'icon' => '<i class="fa fa-map-marker"></i>',
                'isActive' => (Yii::$app->controller->module
                    && Yii::$app->controller->module->id === 'usermap'
                    && Yii::$app->controller->id === 'map'),
                'sortOrder' => $settings->get('map_widget_location_sort_order', 400),
            ]);
        } catch (\Throwable $e) {
            Yii::error($e);
        }
    }

    public static function onDashboardSidebarInit($event) {
        try {
            $settings = Yii::$app->getModule('usermap')->settings;
            if ($settings->get('map_widget_location') !== 'dashboard_sidebar') {
                return;
            }

            $currentIdentity = Yii::$app->user->identity;
            if ($currentIdentity === null || Yii::$app->user->isGuest) {
                return;
            }
            
            $event->sender->addWidget(
                MapView::class,
                [
                    'height' => '20em',
                    'showAsPanel' => true,
                    'link' => Url::to(['/usermap/map'])
                ],
                ['sortOrder' => $settings->get('map_widget_location_sort_order', 400)]
            );
        } catch (\Throwable $e) {
            Yii::error($e);
        }
    }
}
