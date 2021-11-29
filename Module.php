<?php
/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap;

use yii\helpers\Url;

class Module extends \humhub\components\Module {
    /**
     * @inheritdoc
     */
    public $resourcesPath = 'resources';

    /**
     * @inheritdoc
     */
    public function getConfigUrl() {
        return Url::to(['/usermap/admin/index']);
    }
}
