<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\modules\dashboard\widgets\Sidebar as DashboardSidebar;
use humhub\modules\marketplace\components\OnlineModuleManager;
use humhub\widgets\TopMenu;

return [
    'id' => 'usermap',
    'class' => 'humhub\modules\usermap\Module',
    'namespace' => 'humhub\modules\usermap',
    'events' => [
        [TopMenu::class, TopMenu::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onTopMenuInit']],
        [DashboardSidebar::class, DashboardSidebar::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onDashboardSidebarInit']],
        [OnlineModuleManager::class, OnlineModuleManager::EVENT_AFTER_UPDATE, ['\humhub\modules\usermap\Events', 'onAfterUpdate']]
    ]
];