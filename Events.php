<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap;

use humhub\modules\ui\menu\MenuLink;
use humhub\modules\usermap\jobs\GeocodeAddress;
use humhub\modules\usermap\widgets\MapView;
use Yii;
use yii\helpers\Url;
use yii\queue\ExecEvent;

class Events
{
    public static function onTopMenuInit($event): void
    {
        $module = Yii::$app->getModule('usermap');
        if (Yii::$app->user->isGuest || $module->getMapWidgetLocation() !== 'top_menu') {
            return;
        }

        $controller = Yii::$app->controller;
        $event->sender->addEntry(new MenuLink([
            'label' => Yii::t('UsermapModule.base', 'Usermap'),
            'url' => ['/usermap/map'],
            'icon' => 'map-marker',
            'isActive' => $controller && $controller->module->id === 'usermap' && $controller->id === 'map',
            'sortOrder' => (int) $module->settings->get('map_widget_location_sort_order', 400),
        ]));
    }

    public static function onDashboardSidebarInit($event): void
    {
        $module = Yii::$app->getModule('usermap');
        if (Yii::$app->user->isGuest || $module->getMapWidgetLocation() !== 'dashboard_sidebar') {
            return;
        }

        $event->sender->addWidget(MapView::class, [
            'id' => 'usermap-dashboard-map',
            'height' => '20em',
            'showAsPanel' => true,
            'link' => Url::to(['/usermap/map']),
        ], ['sortOrder' => (int) $module->settings->get('map_widget_location_sort_order', 400)]);
    }

    public static function onAfterUpdate($event): void
    {
        if ($event->moduleId === 'usermap') {
            $module = Yii::$app->getModule('usermap');
            $module->settings->set('map_widget_location', $module->getMapWidgetLocation());
        }
    }

    public static function onQueueAfterExec(ExecEvent $event): void
    {
        if ($event->job instanceof GeocodeAddress) {
            $event->job->releaseReservation($event->id);
        }
    }
}
