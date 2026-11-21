<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\widgets;

use humhub\components\Widget;
use humhub\modules\queue\driver\Instant;
use humhub\modules\user\models\User;
use humhub\modules\usermap\components\Geocoder;
use humhub\modules\usermap\components\MapMembers;
use humhub\modules\usermap\jobs\GeocodeAddress;
use humhub\modules\usermap\models\admin\EditForm;
use Yii;
use yii\helpers\Url;
use yii\queue\sync\Queue as SyncQueue;

class MapView extends Widget
{
    /** @var string Map height as a CSS value. The dashboard supplies its own preview height. */
    public $height = '75vh';

    /** @var bool Show the map in a dashboard panel. */
    public $showAsPanel = false;

    /** @var string|null Destination for the dashboard preview. */
    public $link;

    public function run()
    {
        $viewer = Yii::$app->user->getIdentity();
        if (!$viewer instanceof User) {
            return '';
        }

        $module = Yii::$app->getModule('usermap');
        $settings = $module->settings;
        $center = Geocoder::normalizeCoordinates([
            'latitude' => $settings->get('osm_map_center_latitude', 51.0951),
            'longitude' => $settings->get('osm_map_center_longitude', 10.2714),
        ]) ?? ['latitude' => 51.0951, 'longitude' => 10.2714];
        $zoom = filter_var($settings->get('osm_map_center_zoom', 5), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 19],
        ]);
        ['provider' => $provider, 'apiKey' => $apiKey] = $module->getGeocodingConfiguration();
        $tileServer = EditForm::normalizeTileServer($settings->get('osm_tile_server'));
        [$users, $missing] = $this->getMarkers($viewer, $provider, $apiKey);
        [$hasQueuedLookups, $lookupOptions] = $this->prepareLookups($viewer, $provider, $missing);

        return $this->render('mapView', [
            'id' => $this->getId(),
            'height' => $this->height,
            'link' => $this->link,
            'showAsPanel' => $this->showAsPanel,
            'hasQueuedLookups' => $hasQueuedLookups,
            'mapOptions' => [
                'tileServer' => EditForm::tileServerError($tileServer) === null ? $tileServer : null,
                'geocodingAttribution' => $module->getCoordinatesCallback === null
                    ? Geocoder::getAttribution($provider)
                    : null,
                'center' => [$center['latitude'], $center['longitude']],
                'zoom' => $zoom === false ? 5 : $zoom,
                'users' => $users,
            ] + $lookupOptions,
        ]);
    }

    private function getMarkers(User $viewer, string $provider, string $apiKey): array
    {
        $module = Yii::$app->getModule('usermap');
        if ($module->getCoordinatesCallback === null && $provider !== 'photon' && $apiKey === '') {
            return [[], []];
        }

        $geocoder = Yii::createObject(Geocoder::class);
        $members = new MapMembers();
        $users = [];
        $missing = [];
        foreach ($members->addresses($viewer) as [$user, $address]) {
            try {
                $coordinates = $module->getCoordinatesCallback !== null
                    ? Geocoder::normalizeCoordinates(call_user_func($module->getCoordinatesCallback, $user))
                    : $geocoder->getCached($address ?? '', $provider, $apiKey);
                if ($coordinates === false) {
                    $lookupId = Geocoder::lookupId($address, $provider, $apiKey);
                    $missing[$lookupId][] = (int) $user->id;
                } elseif ($coordinates !== null) {
                    $users[] = MapMembers::marker($user, $address, $coordinates, (bool) $this->link);
                }
            } catch (\Throwable $error) {
                Yii::error('Could not prepare user #' . $user->id . ' for the map (' . get_class($error) . ').', 'usermap');
            }
        }

        return [$users, $missing];
    }

    private function prepareLookups(User $viewer, string $provider, array $missing): array
    {
        if ($missing === []) {
            return [false, []];
        }
        $queue = Yii::$app->queue;
        // Resolve HERE cache misses for the open map so it can use the coordinates
        // even if the response headers prevent Geocoder from caching them.
        if ($provider === 'here' || $queue instanceof SyncQueue || $queue instanceof Instant) {
            return [false, $this->deferredOptions($viewer, $missing)];
        }
        $queued = [];
        foreach ($missing as $lookupId => $memberIds) {
            try {
                (new GeocodeAddress([
                    'lookupId' => $lookupId,
                    'viewerId' => (int) $viewer->id,
                    'memberId' => $memberIds[0],
                    'language' => Yii::$app->language,
                ]))->enqueue();
                $queued[$lookupId] = $memberIds;
            } catch (\Throwable $error) {
                Yii::error('Could not queue a map lookup (' . get_class($error) . ').', 'usermap');
                break;
            }
        }
        $token = $queued ? MapMembers::watch($viewer, $queued, (bool) $this->link) : null;
        return [$token !== null, $token === null ? [] : [
            'updatesUrl' => Url::to(['/usermap/map/updates']),
            'updatesToken' => $token,
        ]];
    }

    private function deferredOptions(User $viewer, array $missing): array
    {
        $tokens = [];
        foreach ($missing as $lookupId => $memberIds) {
            $token = Yii::$app->security->generateRandomString();
            // Only opaque, viewer-bound tokens are exposed, including in the dashboard preview.
            if (Yii::$app->cache->set(['usermap.deferred', (int) $viewer->id, $token], [
                'lookupId' => $lookupId,
                'memberIds' => $memberIds,
                'preview' => (bool) $this->link,
            ], 3600)) {
                $tokens[] = $token;
            }
        }
        return ['lookupUrl' => Url::to(['/usermap/map/lookup']), 'lookupTokens' => $tokens];
    }
}
