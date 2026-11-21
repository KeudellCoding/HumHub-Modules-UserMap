<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\components;

use Yii;
use yii\base\Component;
use yii\helpers\Json;
use yii\httpclient\Client;
use yii\httpclient\Response;
use yii\web\HeaderCollection;

/**
 * Resolves addresses without exposing API keys to the browser or error cache.
 */
class Geocoder extends Component
{
    public const ERROR_CACHE_KEY = 'usermap.geocoding.last-error.v2';
    private const RETRY_AFTER = 300;
    private const MAX_COORDINATE_CACHE_DURATION = 30 * 86400;
    private const PROVIDERS = ['google', 'here', 'locationiq', 'opencage', 'photon'];
    private const SHARED_SERVICE_PROVIDERS = ['locationiq', 'opencage', 'photon'];

    /** @var Client|null Can be supplied to use a different HTTP transport. */
    public $client;

    /** @var \yii\mutex\Mutex|null Defaults to HumHub's shared mutex component. */
    public $mutex;

    public static function requiresApiKey(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && $provider !== 'photon';
    }

    public static function getAttribution(string $provider): ?array
    {
        return match ($provider) {
            'google' => ['label' => Yii::t('UsermapModule.base', 'Geocoding by Google Maps'), 'url' => 'https://maps.google.com/'],
            'here' => ['label' => Yii::t('UsermapModule.base', 'Geocoding by HERE'), 'url' => 'https://www.here.com/en-gb/terms/general-content-supplier-terms-and-notices'],
            'locationiq' => ['label' => 'Search by LocationIQ.com', 'url' => 'https://locationiq.com/'],
            'opencage' => ['label' => Yii::t('UsermapModule.base', 'Geocoding by OpenCage'), 'url' => 'https://opencagedata.com/'],
            'photon' => ['label' => Yii::t('UsermapModule.base', 'Geocoding by Photon'), 'url' => 'https://photon.komoot.io/'],
            default => null,
        };
    }

    private static function cacheKey(string $address, string $provider, string $apiKey): array
    {
        $key = self::requiresApiKey($provider) ? trim($apiKey) : '';
        return ['usermap.geocoding.v3', $provider, hash('sha256', $key), $address];
    }

    public static function lookupId(string $address, string $provider, string $apiKey): string
    {
        return hash('sha256', serialize(self::cacheKey($address, $provider, $apiKey)));
    }

    /** Coordinates on a hit, false for work to schedule, null for a failure or quota pause. */
    public function getCached(string $address, string $provider, string $apiKey): array|false|null
    {
        if ($provider === '' || trim($address) === '') {
            return null;
        }

        if (!in_array($provider, self::PROVIDERS, true)) {
            $this->recordError($provider, 'unsupported-provider');
            return null;
        }
        if (self::requiresApiKey($provider) && trim($apiKey) === '') {
            $this->recordError($provider, 'missing-api-key');
            return null;
        }

        $cacheKey = self::cacheKey($address, $provider, $apiKey);
        $cached = Yii::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return self::normalizeCoordinates($cached);
        }
        return Yii::$app->cache->get(['usermap.geocoding.pause', $provider, $cacheKey[2]]) === false ? false : null;
    }

    public function locate(string $address, string $provider, string $apiKey): ?array
    {
        $cached = $this->getCached($address, $provider, $apiKey);
        if ($cached !== false) {
            return $cached;
        }

        $lock = 'usermap.resolve.' . self::lookupId($address, $provider, $apiKey);
        $mutex = $this->mutex ?? Yii::$app->get('mutex');
        if (!$mutex->acquire($lock, 5)) {
            return null;
        }

        try {
            $cached = $this->getCached($address, $provider, $apiKey);
            return $cached === false ? $this->request($address, $provider, $apiKey) : $cached;
        } finally {
            $mutex->release($lock);
        }
    }

    private function request(string $address, string $provider, string $apiKey): ?array
    {
        $apiKey = self::requiresApiKey($provider) ? trim($apiKey) : '';
        $cacheKey = self::cacheKey($address, $provider, $apiKey);
        $pauseKey = ['usermap.geocoding.pause', $provider, $cacheKey[2]];
        try {
            if (!$this->throttle($provider, $apiKey)) {
                return null;
            }
            // Another request may have reached the quota while this one waited.
            if (Yii::$app->cache->get($pauseKey) !== false) {
                return null;
            }
            [$url, $query] = $this->getRequest($address, $provider, $apiKey);
            $client = $this->client ?? new GeocodingClient();
            $response = $client->get($url, $query)
                ->setHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'HumHub-Usermap (+https://github.com/KeudellCoding/HumHub-Modules-UserMap)',
                ])
                ->send();

            if (!$response->isOk) {
                $this->handleHttpError($provider, $response, $pauseKey);
                Yii::$app->cache->set($cacheKey, null, self::RETRY_AFTER);
                return null;
            }

            $data = Json::decode($response->content);
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Invalid geocoding response.');
            }

            $coordinates = $this->getCoordinates($provider, $data);
            if ($coordinates === null) {
                if ($provider === 'google' && ($data['status'] ?? null) === 'OVER_QUERY_LIMIT') {
                    Yii::$app->cache->set($pauseKey, true, self::RETRY_AFTER);
                }
                $this->recordResultError($provider, $data, $apiKey);
                Yii::$app->cache->set($cacheKey, null, self::RETRY_AFTER);
                return null;
            }

            $duration = match ($provider) {
                'google' => self::MAX_COORDINATE_CACHE_DURATION,
                'here' => $this->getHereCacheDuration($response->headers),
                'locationiq' => 48 * 3600,
                default => 0,
            };
            if ($duration !== null) {
                Yii::$app->cache->set($cacheKey, $coordinates, $duration);
            }
            return $coordinates;
        } catch (\Throwable) {
            $this->recordError($provider, 'connection-error');
            Yii::$app->cache->set($cacheKey, null, self::RETRY_AFTER);
            return null;
        }
    }

    private function handleHttpError(string $provider, Response $response, array $pauseKey): void
    {
        $status = (int) $response->statusCode;
        if (in_array($status, [402, 429], true)) {
            $now = time();
            $retryAfter = $response->headers->get('Retry-After');
            $retryAt = is_numeric($retryAfter) ? $now + (int) $retryAfter : strtotime((string) $retryAfter);
            if ($provider === 'opencage' && $status === 402) {
                $retryAt = max((int) $retryAt, (int) $response->headers->get('X-RateLimit-Reset'));
            }
            Yii::$app->cache->set($pauseKey, true, max(self::RETRY_AFTER, (int) $retryAt - $now));
        }

        if ($provider === 'locationiq' && $status === 404) {
            $data = json_decode($response->content, true);
            if (($data['error'] ?? null) === 'Unable to geocode') {
                $this->recordError($provider, 'no-match');
                return;
            }
        }
        $this->recordError($provider, 'http-error', ['status' => $status]);
    }

    private function recordResultError(string $provider, array $data, string $apiKey): void
    {
        $message = $data['error_message'] ?? $data['message'] ?? $data['error_description'] ?? $data['error'] ?? null;
        if ($message === null && $provider === 'google') {
            $status = $data['status'] ?? null;
            if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                $message = $status;
            }
        } elseif ($message === null && $provider === 'opencage' && (int) ($data['status']['code'] ?? 0) !== 200) {
            $message = $data['status']['message'] ?? null;
        }

        if ($message === null) {
            $this->recordError($provider, 'no-match');
        } elseif (!is_string($message)) {
            $this->recordError($provider, 'invalid-response');
        } else {
            $this->recordError($provider, 'provider-error', [
                'message' => str_replace([$apiKey, urlencode($apiKey), rawurlencode($apiKey)], '[redacted]', $message),
            ]);
        }
    }

    private function getHereCacheDuration(HeaderCollection $headers): ?int
    {
        $cacheControl = implode(',', $headers->get('Cache-Control', [], false));
        if (preg_match('/(?:^|,)\s*(?:private|no-store|no-cache)\s*(?:=|,|$)/i', $cacheControl)) {
            return null;
        }

        $now = time();
        $date = strtotime((string) $headers->get('Date')) ?: $now;
        if (preg_match('/(?:^|,)\s*s-maxage\s*=\s*"?(\d+)"?\s*(?:,|$)/i', $cacheControl, $matches)
            || preg_match('/(?:^|,)\s*max-age\s*=\s*"?(\d+)"?\s*(?:,|$)/i', $cacheControl, $matches)) {
            $lifetime = (int) $matches[1];
        } else {
            $expires = strtotime((string) $headers->get('Expires'));
            $lifetime = $expires === false ? 0 : $expires - $date;
        }

        $age = max((int) $headers->get('Age'), $now - $date, 0);
        $duration = min($lifetime, self::MAX_COORDINATE_CACHE_DURATION) - $age;
        return $duration > 0 ? $duration : null;
    }

    public static function normalizeCoordinates($coordinates): ?array
    {
        if (!is_array($coordinates)
            || !isset($coordinates['latitude'], $coordinates['longitude'])
            || !is_numeric($coordinates['latitude'])
            || !is_numeric($coordinates['longitude'])) {
            return null;
        }

        $latitude = (float) $coordinates['latitude'];
        $longitude = (float) $coordinates['longitude'];
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    protected function getRequest(string $address, string $provider, string $apiKey): array
    {
        return match ($provider) {
            'google' => [
                'https://maps.googleapis.com/maps/api/geocode/json',
                ['address' => $address, 'key' => $apiKey],
            ],
            'here' => [
                'https://geocode.search.hereapi.com/v1/geocode',
                ['q' => $address, 'apiKey' => $apiKey, 'limit' => 1],
            ],
            'locationiq' => [
                'https://us1.locationiq.com/v1/search',
                ['q' => $address, 'key' => $apiKey, 'format' => 'json', 'limit' => 1],
            ],
            'opencage' => [
                'https://api.opencagedata.com/geocode/v1/json',
                ['q' => $address, 'key' => $apiKey, 'limit' => 1, 'no_annotations' => 1, 'no_record' => 1],
            ],
            'photon' => [
                'https://photon.komoot.io/api/',
                ['q' => $address, 'limit' => 1],
            ],
        };
    }

    private function getCoordinates(string $provider, array $data): ?array
    {
        $position = match ($provider) {
            'google' => ($data['status'] ?? null) === 'OK' ? ($data['results'][0]['geometry']['location'] ?? null) : null,
            'photon' => isset($data['features'][0]['geometry']['coordinates'])
                ? ['lat' => $data['features'][0]['geometry']['coordinates'][1] ?? null, 'lng' => $data['features'][0]['geometry']['coordinates'][0] ?? null]
                : null,
            'here' => $data['items'][0]['position'] ?? null,
            'locationiq' => ['lat' => $data[0]['lat'] ?? null, 'lng' => $data[0]['lon'] ?? null],
            'opencage' => (int) ($data['status']['code'] ?? 0) === 200 ? ($data['results'][0]['geometry'] ?? null) : null,
        };

        return self::normalizeCoordinates([
            'latitude' => $position['lat'] ?? null,
            'longitude' => $position['lng'] ?? null,
        ]);
    }

    private function throttle(string $provider, string $apiKey): bool
    {
        if (!in_array($provider, self::SHARED_SERVICE_PROVIDERS, true)) {
            return true;
        }

        $scope = $provider . '.' . hash('sha256', $apiKey);
        $mutex = $this->mutex ?? Yii::$app->get('mutex');
        $lock = 'usermap.geocoding.' . $scope;
        if (!$mutex->acquire($lock, 5)) {
            return false;
        }

        try {
            $cacheKey = ['usermap.geocoding.last-request', $scope];
            $lastRequest = Yii::$app->cache->get($cacheKey);
            $delay = $lastRequest === false ? 0 : 1 - (microtime(true) - $lastRequest);
            if ($delay > 0) {
                usleep((int) ceil($delay * 1000000));
            }
            Yii::$app->cache->set($cacheKey, microtime(true), 60);
            return true;
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Translate shared cached errors when they are displayed, using the viewer's language.
     */
    public static function getLastError(): array|false
    {
        $error = Yii::$app->cache->get(self::ERROR_CACHE_KEY);
        if (!is_array($error) || !isset($error['code'])) {
            return false;
        }

        $params = $error['params'] ?? [];
        $message = match ($error['code']) {
            'unsupported-provider' => Yii::t('UsermapModule.base', 'Select a supported geocoding provider.'),
            'missing-api-key' => Yii::t('UsermapModule.base', 'Select a geocoding provider and enter its API key.'),
            'http-error' => Yii::t('UsermapModule.base', 'Geocoding request failed (HTTP {status}).', $params),
            'no-match' => Yii::t('UsermapModule.base', 'No matching location found.'),
            'invalid-response' => Yii::t('UsermapModule.base', 'Invalid geocoding response.'),
            'provider-error' => Yii::t('UsermapModule.base', 'The geocoding provider returned an error: {message}', $params),
            'connection-error' => Yii::t('UsermapModule.base', 'Unable to read a valid response from the geocoding provider. Check the connection and API settings.'),
            default => Yii::t('UsermapModule.base', 'Unknown geocoding error.'),
        };

        return ['provider' => $error['provider'] ?? '', 'error_message' => $message];
    }

    private function recordError(string $provider, string $code, array $params = []): void
    {
        Yii::$app->cache->set(self::ERROR_CACHE_KEY, [
            'provider' => $provider,
            'code' => $code,
            'params' => $params,
        ], 86400);
    }
}
