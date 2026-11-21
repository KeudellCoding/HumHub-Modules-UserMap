<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\jobs;

use humhub\modules\queue\ActiveJob;
use humhub\modules\queue\interfaces\ExclusiveJobInterface;
use humhub\modules\queue\models\QueueExclusive;
use humhub\modules\user\models\User;
use humhub\modules\usermap\components\MapMembers;
use Yii;

class GeocodeAddress extends ActiveJob implements ExclusiveJobInterface
{
    public string $lookupId;
    public int $memberId;
    public int $viewerId;
    public string $language;

    public function getExclusiveJobId(): string
    {
        // HumHub's queue_exclusive.id allows 50 characters.
        return self::reservationId($this->lookupId);
    }

    public static function reservationId(string $lookupId): string
    {
        return 'usermap.' . substr($lookupId, 0, 40);
    }

    public function enqueue(): void
    {
        // HumHub checks exclusivity before pushing and records it after pushing.
        $lock = $this->queueLock();
        if (!Yii::$app->mutex->acquire($lock, 0)) {
            return;
        }
        try {
            Yii::$app->queue->push($this);
        } finally {
            Yii::$app->mutex->release($lock);
        }
    }

    public function releaseReservation($messageId): void
    {
        // A fast worker can finish while the submitting request is still recording exclusivity.
        $lock = $this->queueLock();
        if (!Yii::$app->mutex->acquire($lock, 5)) {
            return;
        }
        try {
            QueueExclusive::deleteAll(['id' => $this->getExclusiveJobId(), 'job_message_id' => $messageId]);
        } finally {
            Yii::$app->mutex->release($lock);
        }
    }

    private function queueLock(): string
    {
        return 'enqueue.' . $this->getExclusiveJobId();
    }

    public function run(): void
    {
        // HumHub memoizes group membership and permissions per request. A worker can run several jobs.
        Yii::$app->runtimeCache->flush();
        $module = Yii::$app->getModule('usermap');
        if ($module === null || !$module->getIsEnabled()) {
            return;
        }
        $viewer = User::find()->active()->andWhere(['user.id' => $this->viewerId])->one();
        if ($viewer === null) {
            return;
        }

        // Long-lived workers may have loaded the settings before a key rotation.
        $module->settings->reload();
        $previousIdentity = Yii::$app->user->getIdentity();
        $previousLanguage = Yii::$app->language;
        try {
            Yii::$app->user->setIdentity($viewer);
            Yii::$app->language = $this->language;
            (new MapMembers())->resolve($viewer, [$this->memberId], $this->lookupId, true);
        } finally {
            Yii::$app->user->setIdentity($previousIdentity);
            Yii::$app->language = $previousLanguage;
        }
    }
}
