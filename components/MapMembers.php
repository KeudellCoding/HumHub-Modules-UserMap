<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\components;

use Generator;
use humhub\modules\queue\models\QueueExclusive;
use humhub\modules\usermap\jobs\GeocodeAddress;
use humhub\libs\Iso3166Codes;
use humhub\modules\user\models\ProfileField;
use humhub\modules\user\models\User;
use humhub\modules\user\permissions\ViewAboutPage;
use Yii;

class MapMembers
{
    private ?array $visibleAddressFields = null;

    /** Yield the current address only for profiles this viewer may locate. */
    public function addresses(User $viewer, ?array $memberIds = null): Generator
    {
        $module = Yii::$app->getModule('usermap');
        $query = User::find()->available($viewer)->with('profile');
        if ($memberIds !== null) {
            $query->andWhere(['user.id' => $memberIds]);
        }
        foreach ($query->each(100) as $user) {
            try {
                if (!$user->getPermissionManager($viewer)->can(ViewAboutPage::class)
                    || ($module->showOnMapCallback !== null && call_user_func($module->showOnMapCallback, $user) !== true)) {
                    continue;
                }
                $address = $this->getFormatedAddress($user);
            } catch (\Throwable $error) {
                Yii::error('Could not read map address for user #' . $user->id . ' (' . get_class($error) . ').', 'usermap');
                continue;
            }
            yield [$user, $address];
        }
    }

    public static function marker(User $user, ?string $address, array $coordinates, bool $preview): array
    {
        $marker = ['coords' => $coordinates];
        if (!$preview) {
            $marker['displayname'] = $user->displayName;
            $marker['profileurl'] = $user->getUrl();
            $marker['formatedaddress'] = $address;
        }
        return $marker;
    }

    /** Recheck the address and access when a worker or a deferred browser request actually needs it. */
    public function resolve(User $viewer, array $memberIds, string $lookupId, bool $preview): array
    {
        $module = Yii::$app->getModule('usermap');
        if ($module === null || $module->getCoordinatesCallback !== null) {
            return [];
        }
        ['provider' => $provider, 'apiKey' => $apiKey] = $module->getGeocodingConfiguration();
        $markers = [];
        $coordinates = null;
        foreach ($this->addresses($viewer, $memberIds) as [$user, $address]) {
            if ($address === null || Geocoder::lookupId($address, $provider, $apiKey) !== $lookupId) {
                continue;
            }
            $coordinates ??= Yii::createObject(Geocoder::class)->locate($address, $provider, $apiKey);
            if ($coordinates === null) {
                break;
            }
            $markers[] = self::marker($user, $address, $coordinates, $preview);
        }
        return $markers;
    }

    public static function watch(User $viewer, array $missing, bool $preview): ?string
    {
        $token = Yii::$app->security->generateRandomString();
        return Yii::$app->cache->set(['usermap.watch', (int) $viewer->id, $token], [$missing, $preview], 3600) ? $token : null;
    }

    public function updates(User $viewer, string $token): array
    {
        $watch = Yii::$app->cache->get(['usermap.watch', (int) $viewer->id, $token]);
        $module = Yii::$app->getModule('usermap');
        if ($watch === false || $module->getCoordinatesCallback !== null) {
            return ['users' => [], 'next' => null];
        }
        [$missing, $preview] = $watch;
        ['provider' => $provider, 'apiKey' => $key] = $module->getGeocodingConfiguration();
        // Read reservations first so a finishing worker's cache write is visible on this or the next poll.
        $reservations = array_map([GeocodeAddress::class, 'reservationId'], array_keys($missing));
        $pending = [];
        foreach (array_chunk($reservations, 500) as $chunk) {
            $pending += QueueExclusive::find()->select(['job_message_id', 'id'])
                ->where(['id' => $chunk])->andWhere(['<>', 'job_status', \yii\queue\Queue::STATUS_DONE])
                ->indexBy('id')->column();
        }
        $users = [];
        $active = [];
        $remaining = [];
        $geocoder = Yii::createObject(Geocoder::class);
        foreach (array_chunk(array_merge(...array_values($missing)), 500) as $ids) {
            foreach ($this->addresses($viewer, $ids) as [$member, $address]) {
                if ($address === null) {
                    continue;
                }
                $lookupId = Geocoder::lookupId($address, $provider, $key);
                if (!isset($missing[$lookupId]) || !in_array((int) $member->id, $missing[$lookupId], true)) {
                    continue;
                }
                $coords = $geocoder->getCached($address, $provider, $key);
                if (is_array($coords)) {
                    $users[] = self::marker($member, $address, $coords, $preview);
                } elseif ($coords === false && isset($pending[GeocodeAddress::reservationId($lookupId)])) {
                    if (!isset($active[$lookupId])) {
                        try {
                            $active[$lookupId] = !Yii::$app->queue->isDone($pending[GeocodeAddress::reservationId($lookupId)]);
                        } catch (\yii\base\InvalidArgumentException) {
                            $active[$lookupId] = false;
                        }
                    }
                    if ($active[$lookupId]) {
                        $remaining[$lookupId][] = (int) $member->id;
                    } else {
                        // The worker may have written coordinates between the cache read and the status check.
                        $coords = $geocoder->getCached($address, $provider, $key);
                        if (is_array($coords)) {
                            $users[] = self::marker($member, $address, $coords, $preview);
                        }
                    }
                }
            }
        }
        $next = null;
        if ($remaining) {
            $next = $remaining === $missing ? $token : self::watch($viewer, $remaining, $preview);
        }
        return ['users' => $users, 'next' => $next];
    }

    public function getFormatedAddress(User $user): ?string
    {
        $module = Yii::$app->getModule('usermap');
        if ($module->getFormatedAddressCallback !== null) {
            $address = call_user_func($module->getFormatedAddressCallback, $user);
            return is_string($address) && trim($address) !== '' ? $address : null;
        }

        // Custom installations can remove the standard address fields or omit a profile.
        $profile = $user->profile;
        if ($profile === null) {
            return null;
        }

        $fields = $profile->getAttributes($this->getVisibleAddressFields());
        $zip = trim((string) ($fields['zip'] ?? ''));
        $city = trim((string) ($fields['city'] ?? ''));
        $country = trim((string) ($fields['country'] ?? ''));
        $locality = trim($zip . ' ' . $city);
        $isPartial = $zip === '' || $city === '';
        if ($locality === '' || ($isPartial && $country === '')) {
            return null;
        }

        $parts = [];
        if ($module->settings->get('geocoding_use_street', true)) {
            $street = trim((string) ($fields['street'] ?? ''));
            if ($street !== '') {
                $parts[] = $street;
            }
        }
        $parts[] = $locality;
        if ($country !== '') {
            // Spell out ISO codes in partial queries to help disambiguate the country.
            $parts[] = $isPartial && Iso3166Codes::isValid($country)
                ? Iso3166Codes::country(strtoupper($country), false)
                : $country;
        }

        return implode(', ', $parts);
    }

    protected function getVisibleAddressFields(): array
    {
        return $this->visibleAddressFields ??= ProfileField::find()
            ->select('internal_name')
            ->where(['visible' => 1, 'internal_name' => ['zip', 'city', 'country', 'street']])
            ->column();
    }
}
