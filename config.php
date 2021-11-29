<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\modules\dashboard\widgets\Sidebar as DashboardSidebar;
use humhub\modules\directory\widgets\Menu as DirectoryMenu;
use humhub\widgets\TopMenu;

return [
    'id' => 'usermap',
    'class' => 'humhub\modules\usermap\Module',
    'namespace' => 'humhub\modules\usermap',
    'events' => [
        [TopMenu::class, TopMenu::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onTopMenuInit']],
        [DirectoryMenu::class, DirectoryMenu::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onDirectoryMenuInit']],
        [DashboardSidebar::class, DashboardSidebar::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onDashboardSidebarInit']]
    ]
];
