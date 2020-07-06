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
use yii\web\JsonParser;

class Module extends \humhub\components\Module {
    /**
     * @inheritdoc
     */
    public function getConfigUrl() {
        return Url::to(['/usermap/admin/index']);
    }
}
