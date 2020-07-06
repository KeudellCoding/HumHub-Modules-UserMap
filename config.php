<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

use humhub\widgets\BaseMenu;
use humhub\components\Application;

return [
    'id' => 'usermap',
    'class' => 'humhub\modules\usermap\Module',
    'namespace' => 'humhub\modules\usermap',
    'events' => [
        [\humhub\modules\directory\widgets\Menu::class, BaseMenu::EVENT_INIT, ['\humhub\modules\usermap\Events', 'onDirectoryMenuInit']]
    ]
];
