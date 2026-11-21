<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

$protected = getenv('HUMHUB_PROTECTED_PATH') ?: '/opt/humhub/protected';
require $protected . '/vendor/autoload.php';
require $protected . '/vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@humhub', $protected . '/humhub');
Yii::setAlias('@humhub/modules/usermap', dirname(__DIR__));
Yii::setAlias('@usermap', dirname(__DIR__));
