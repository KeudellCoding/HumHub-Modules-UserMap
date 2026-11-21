<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\tests;

use humhub\components\SettingsManager;
use humhub\modules\usermap\components\Geocoder;
use humhub\modules\usermap\components\GeocodingClient;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\console\Application;
use yii\helpers\Json;
use yii\httpclient\Client;
use yii\httpclient\CurlTransport;
use yii\httpclient\Transport;
use yii\i18n\PhpMessageSource;
use yii\mutex\Mutex;

class RecordingCache extends ArrayCache
{
    public array $writes = [];

    public function set($key, $value, $duration = null, $dependency = null)
    {
        $this->writes[] = ['value' => $value, 'duration' => $duration];
        return parent::set($key, $value, $duration, $dependency);
    }
}

class FixtureMutex extends Mutex
{
    protected function acquireLock($name, $timeout = 0)
    {
        return true;
    }

    protected function releaseLock($name)
    {
        return true;
    }
}

class FixtureTransport extends Transport
{
    public array $responses = [];
    public array $requests = [];
    public array $sentAt = [];

    public function send($request)
    {
        $request->beforeSend();
        $request->prepare();
        $this->requests[] = $request->getFullUrl();
        $this->sentAt[] = microtime(true);
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $request->client->createResponse($next['content'], $next['headers']);
    }
}

class GeocoderTest extends TestCase
{
    private FixtureTransport $transport;
    private Geocoder $geocoder;
    private FixtureMutex $mutex;

    protected function setUp(): void
    {
        new Application([
            'id' => 'usermap-tests',
            'basePath' => __DIR__,
            'language' => 'en-US',
            'components' => [
                'cache' => ['class' => RecordingCache::class],
                'i18n' => [
                    'translations' => [
                        'UsermapModule.*' => ['class' => PhpMessageSource::class],
                    ],
                ],
            ],
        ]);
        $this->transport = new FixtureTransport();
        $this->mutex = new FixtureMutex();
        $this->geocoder = new Geocoder([
            'client' => new Client(['transport' => $this->transport]),
            'mutex' => $this->mutex,
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
    }

    /** @dataProvider providerResponses */
    public function testCurrentProviderEndpointsAndResponseFormats(string $provider, array $response, string $endpoint, ?string $keyParameter): void
    {
        $this->respond($response);
        $address = 'Straße 1 & Address';
        self::assertSame(['latitude' => 0.0, 'longitude' => 13.4], $this->geocoder->locate($address, $provider, $provider === 'photon' ? '' : 'fake-key'));
        $request = $this->transport->requests[0];
        self::assertSame($endpoint, strtok($request, '?'));
        parse_str(parse_url($request, PHP_URL_QUERY), $query);
        if ($keyParameter !== null) {
            self::assertSame('fake-key', $query[$keyParameter]);
        } else {
            self::assertArrayNotHasKey('key', $query);
            self::assertArrayNotHasKey('apiKey', $query);
            self::assertArrayNotHasKey('access_token', $query);
        }
        self::assertSame($address, $query[$provider === 'google' ? 'address' : 'q']);
        if ($provider !== 'google') {
            self::assertSame('1', $query['limit']);
        }
        if ($provider === 'locationiq') {
            self::assertSame('json', $query['format']);
        }
        if ($provider === 'opencage') {
            self::assertSame('1', $query['no_annotations']);
            self::assertSame('1', $query['no_record']);
        }
    }

    public static function providerResponses(): array
    {
        return [
            ['google', ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 0, 'lng' => 13.4]]]]], 'https://maps.googleapis.com/maps/api/geocode/json', 'key'],
            ['here', ['items' => [['position' => ['lat' => 0, 'lng' => 13.4]]]], 'https://geocode.search.hereapi.com/v1/geocode', 'apiKey'],
            ['locationiq', [['lat' => '0', 'lon' => '13.4']], 'https://us1.locationiq.com/v1/search', 'key'],
            ['opencage', ['status' => ['code' => 200, 'message' => 'OK'], 'results' => [['geometry' => ['lat' => 0, 'lng' => 13.4]]]], 'https://api.opencagedata.com/geocode/v1/json', 'key'],
            ['photon', ['features' => [['geometry' => ['type' => 'Point', 'coordinates' => [13.4, 0]]]]], 'https://photon.komoot.io/api/', null],
        ];
    }

    public function testSuccessfulResultsAreCachedAndSeparatedByCredentials(): void
    {
        $response = ['items' => [['position' => ['lat' => 51, 'lng' => 7]]]];
        $this->respond($response, ['cache-control' => 'public, max-age=600']);
        $this->respond($response, ['cache-control' => 'public, max-age=600']);
        $first = $this->geocoder->locate('Address', 'here', 'first-key');
        self::assertSame($first, $this->geocoder->locate('Address', 'here', 'first-key'));
        self::assertCount(1, $this->transport->requests);
        self::assertSame($first, $this->geocoder->locate('Address', 'here', 'second-key'));
        self::assertCount(2, $this->transport->requests);
    }

    public function testCachedResultsDoNotCrossProviders(): void
    {
        $this->respond(['items' => [['position' => ['lat' => 51, 'lng' => 7]]]], ['cache-control' => 'public, max-age=600']);
        $this->respond(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 52, 'lng' => 8]]]]]);
        self::assertSame(51.0, $this->geocoder->locate('Address', 'here', 'key')['latitude']);
        self::assertSame(52.0, $this->geocoder->locate('Address', 'google', 'key')['latitude']);
        self::assertCount(2, $this->transport->requests);
    }

    public function testConcurrentWorkerRechecksTheCacheAfterAcquiringTheLock(): void
    {
        $coordinates = ['latitude' => 52.0, 'longitude' => 13.0];
        $mutex = $this->createMock(Mutex::class);
        $mutex->expects(self::once())->method('acquire')->willReturnCallback(static function () use ($coordinates) {
            Yii::$app->cache->set(['usermap.geocoding.v3', 'here', hash('sha256', 'key'), 'Address'], $coordinates, 300);
            return true;
        });
        $mutex->expects(self::once())->method('release')->willReturn(true);
        $this->geocoder->mutex = $mutex;
        self::assertSame($coordinates, $this->geocoder->locate('Address', 'here', 'key'));
        self::assertCount(0, $this->transport->requests);
    }

    public function testEmptyResultsAreCachedToAvoidRepeatedFailedRequests(): void
    {
        $this->respond(['items' => []]);
        self::assertNull($this->geocoder->locate('Unknown address', 'here', 'key'));
        self::assertNull($this->geocoder->locate('Unknown address', 'here', 'key'));
        self::assertCount(1, $this->transport->requests);
    }

    public function testTransportExceptionsDoNotLeakApiKeys(): void
    {
        $this->transport->responses[] = new \RuntimeException('Could not load https://example.test/?apiKey=secret-token');
        self::assertNull($this->geocoder->locate('Address', 'here', 'secret-token'));
        $error = Json::encode(Yii::$app->cache->get(Geocoder::ERROR_CACHE_KEY));
        self::assertStringNotContainsString('secret-token', $error);
        self::assertStringNotContainsString('example.test', $error);
    }

    public function testDefaultCurlClientUsesTheHumHubProxyAndEnforcesTlsAndTimeout(): void
    {
        $proxy = [
            'proxyEnabled' => true, 'proxyServer' => 'proxy.example.test', 'proxyPort' => 3128,
            'proxyUser' => 'proxy-user', 'proxyPassword' => 'fixture-password', 'proxyNoproxy' => 'localhost',
        ];
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('get')->willReturnCallback(static fn(string $name) => $proxy[$name]);
        Yii::$app->set('settings', $settings);
        Yii::$app->params['curl']['validateSsl'] = false;
        $client = new GeocodingClient();
        self::assertInstanceOf(CurlTransport::class, $client->getTransport());
        $request = $client->get('https://provider.example.test/geocode', ['q' => 'Private address', 'key' => 'fixture-key']);
        $request->beforeSend();
        $options = $request->getOptions();
        self::assertSame('proxy.example.test', $options[CURLOPT_PROXY]);
        self::assertSame(3128, $options[CURLOPT_PROXYPORT]);
        self::assertSame('proxy-user', $options[CURLOPT_PROXYUSERNAME]);
        self::assertSame('fixture-password', $options[CURLOPT_PROXYPASSWORD]);
        self::assertSame('localhost', $options[CURLOPT_NOPROXY]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(5, $options[CURLOPT_TIMEOUT]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $request->prepare();
        self::assertSame('GET https://provider.example.test/geocode', $client->createRequestLogToken(
            'GET',
            $request->getFullUrl(),
            ['Authorization: private'],
            'private',
        ));
    }

    public function testInvalidJsonAndHttpErrorsDoNotBreakMapRendering(): void
    {
        $this->transport->responses[] = ['headers' => ['http-code' => 200], 'content' => '<html>not JSON</html>'];
        self::assertNull($this->geocoder->locate('First address', 'here', 'key'));
        $this->transport->responses[] = ['headers' => ['http-code' => 403], 'content' => 'Denied'];
        self::assertNull($this->geocoder->locate('Second address', 'here', 'key'));
        self::assertStringContainsString('403', Geocoder::getLastError()['error_message']);
    }

    public function testProviderErrorsAreRecordedWithCredentialsRedacted(): void
    {
        $this->respond(['status' => 'REQUEST_DENIED', 'error_message' => 'Invalid token: secret-token']);
        self::assertNull($this->geocoder->locate('Address', 'google', 'secret-token'));
        self::assertSame('The geocoding provider returned an error: Invalid token: [redacted]', Geocoder::getLastError()['error_message']);
        self::assertStringNotContainsString('secret-token', Json::encode(Yii::$app->cache->get(Geocoder::ERROR_CACHE_KEY)));
    }

    public function testMissingConfigurationAndEmptyAddressesDoNotMakeRequests(): void
    {
        self::assertNull($this->geocoder->locate('', 'here', 'key'));
        self::assertNull($this->geocoder->locate('Address', '', ''));
        self::assertFalse(Geocoder::getLastError());
        self::assertNull($this->geocoder->locate('Address', 'unsupported', 'key'));
        self::assertNull($this->geocoder->locate('Address', 'here', ''));
        self::assertCount(0, $this->transport->requests);
    }

    public function testUnsupportedProviderCannotReuseCachedCoordinatesOrSendRequests(): void
    {
        Yii::$app->cache->set(
            ['usermap.geocoding.v3', 'removed-provider', hash('sha256', 'obsolete-key'), 'Address'],
            ['latitude' => 52.0, 'longitude' => 13.0],
        );
        self::assertNull($this->geocoder->locate('Address', 'removed-provider', 'obsolete-key'));
        self::assertCount(0, $this->transport->requests);
        self::assertSame(
            'Select a supported geocoding provider.',
            Geocoder::getLastError()['error_message'],
        );
    }

    /** @dataProvider invalidCoordinates */
    public function testInvalidCoordinatesAreRejected($coordinates): void
    {
        self::assertNull(Geocoder::normalizeCoordinates($coordinates));
    }

    public static function invalidCoordinates(): array
    {
        return [
            [null], [[]], [['latitude' => 0]], [['latitude' => null, 'longitude' => 0]],
            [['latitude' => '', 'longitude' => 0]], [['latitude' => 91, 'longitude' => 0]],
            [['latitude' => 0, 'longitude' => -181]], [['latitude' => NAN, 'longitude' => 0]],
            [['latitude' => INF, 'longitude' => 0]],
        ];
    }

    public function testZeroAndNumericStringCoordinatesAreValid(): void
    {
        self::assertSame(['latitude' => 0.0, 'longitude' => 0.0], Geocoder::normalizeCoordinates(['latitude' => '0', 'longitude' => 0]));
    }

    public function testPhotonIgnoresAStaleApiKeyAndReusesItsCache(): void
    {
        $this->respond(['features' => [['geometry' => ['coordinates' => [13.4, 52.5]]]]]);
        $first = $this->geocoder->locate('Address', 'photon', '');
        self::assertNotNull($first);
        self::assertSame($first, $this->geocoder->locate('Address', 'photon', 'old-provider-secret'));
        self::assertCount(1, $this->transport->requests);
        self::assertStringNotContainsString('old-provider-secret', $this->transport->requests[0]);
    }

    /** @dataProvider emptyNewProviderResponses */
    public function testNewProvidersHandleEmptyResults(string $provider, array $response): void
    {
        $this->respond($response);
        self::assertNull($this->geocoder->locate('Unknown address', $provider, 'fake-key'));
        self::assertCount(1, $this->transport->requests);
        self::assertSame('No matching location found.', Geocoder::getLastError()['error_message']);
    }

    public static function emptyNewProviderResponses(): array
    {
        return [
            ['locationiq', []],
            ['opencage', ['status' => ['code' => 200, 'message' => 'OK'], 'results' => []]],
            ['photon', ['features' => []]],
        ];
    }

    public function testLocationIq404ForAnUnmatchedAddressIsReportedAsNoMatch(): void
    {
        $this->respond(['error' => 'Unable to geocode'], ['http-code' => 404]);
        self::assertNull($this->geocoder->locate('Unknown address', 'locationiq', 'fake-key'));
        self::assertNull($this->geocoder->locate('Unknown address', 'locationiq', 'fake-key'));
        self::assertSame('No matching location found.', Geocoder::getLastError()['error_message']);
        self::assertCount(1, $this->transport->requests);
    }

    public function testGoogleQuotaStatusInSuccessfulHttpResponsePausesLookups(): void
    {
        $this->respond(['status' => 'OVER_QUERY_LIMIT', 'results' => []]);
        self::assertNull($this->geocoder->locate('First address', 'google', 'key'));
        self::assertNull($this->geocoder->locate('Second address', 'google', 'key'));
        self::assertSame('The geocoding provider returned an error: OVER_QUERY_LIMIT', Geocoder::getLastError()['error_message']);
        self::assertCount(1, $this->transport->requests);
    }

    public function testOpenCageRejectsAnErrorStatusEvenIfCoordinatesArePresent(): void
    {
        $this->respond([
            'status' => ['code' => 403, 'message' => 'Invalid secret-token'],
            'results' => [['geometry' => ['lat' => 52, 'lng' => 13]]],
        ]);
        self::assertNull($this->geocoder->locate('Address', 'opencage', 'secret-token'));
        self::assertSame('The geocoding provider returned an error: Invalid [redacted]', Geocoder::getLastError()['error_message']);
    }

    public function testLocationIqCacheExpiresAfter48Hours(): void
    {
        $this->respond([['lat' => '52.5', 'lon' => '13.4']]);
        $coordinates = $this->geocoder->locate('Address', 'locationiq', 'key');
        self::assertNotNull($coordinates);
        $writes = array_values(array_filter(Yii::$app->cache->writes, fn(array $write) => $write['value'] === $coordinates));
        self::assertCount(1, $writes);
        self::assertSame(172800, $writes[0]['duration']);
    }

    public function testGoogleCacheExpiresAndDoesNotReusePreviouslyUnboundedResults(): void
    {
        Yii::$app->cache->set(
            ['usermap.geocoding.v2', 'google', hash('sha256', 'key'), 'Address'],
            ['latitude' => 0.0, 'longitude' => 0.0],
        );
        $this->respond(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 52.5, 'lng' => 13.4]]]]]);
        $coordinates = $this->geocoder->locate('Address', 'google', 'key');
        self::assertSame(['latitude' => 52.5, 'longitude' => 13.4], $coordinates);
        $writes = array_values(array_filter(Yii::$app->cache->writes, fn(array $write) => $write['value'] === $coordinates));
        self::assertCount(1, $writes);
        self::assertSame(30 * 86400, $writes[0]['duration']);
    }

    /** @dataProvider hereCacheHeaders */
    public function testHereCachesCoordinatesOnlyForThePermittedDuration(array $headers, ?int $duration): void
    {
        $this->respond(['items' => [['position' => ['lat' => 52.5, 'lng' => 13.4]]]], $headers);
        $coordinates = $this->geocoder->locate('Address', 'here', 'key');
        self::assertSame(['latitude' => 52.5, 'longitude' => 13.4], $coordinates);
        $writes = array_filter(Yii::$app->cache->writes, fn(array $write) => $write['value'] === $coordinates);
        self::assertSame($duration === null ? [] : [$duration], array_column($writes, 'duration'));
    }

    public static function hereCacheHeaders(): array
    {
        return [
            'no cache headers' => [[], null],
            'private response' => [['cache-control' => 'private, max-age=600'], null],
            'no-store response' => [['cache-control' => 'max-age=600, no-store'], null],
            'already stale' => [['cache-control' => 'max-age=60', 'age' => '60'], null],
            'shared cache override' => [['cache-control' => 'max-age=600, s-maxage=60', 'age' => '15'], 45],
            '30 day cap' => [['cache-control' => 'max-age=9999999'], 30 * 86400],
        ];
    }

    /** @dataProvider quotaErrors */
    public function testQuotaErrorsPauseOtherAddressesButNotOtherCredentials(int $status): void
    {
        $this->transport->responses[] = [
            'headers' => ['http-code' => $status, 'retry-after' => '600'],
            'content' => '{}',
        ];
        self::assertNull($this->geocoder->locate('First address', 'opencage', 'exhausted-key'));
        self::assertNull($this->geocoder->locate('Second address', 'opencage', 'exhausted-key'));
        self::assertCount(1, $this->transport->requests);
        $pauses = array_values(array_filter(Yii::$app->cache->writes, fn(array $write) => $write['value'] === true));
        self::assertCount(1, $pauses);
        self::assertGreaterThanOrEqual(599, $pauses[0]['duration']);

        $this->respond(['status' => ['code' => 200], 'results' => [['geometry' => ['lat' => 52, 'lng' => 13]]]]);
        self::assertNotNull($this->geocoder->locate('Second address', 'opencage', 'new-key'));
        self::assertCount(2, $this->transport->requests);
    }

    public static function quotaErrors(): array
    {
        return [[402], [429]];
    }

    public function testOpenCageDailyQuotaPausesUntilTheDocumentedReset(): void
    {
        $resetAt = time() + 3600;
        $this->respond(['status' => ['code' => 402, 'message' => 'quota exceeded']], [
            'http-code' => 402,
            'x-ratelimit-reset' => (string) $resetAt,
        ]);
        self::assertNull($this->geocoder->locate('First address', 'opencage', 'key'));
        $pauses = array_values(array_filter(Yii::$app->cache->writes, fn(array $write) => $write['value'] === true));
        self::assertCount(1, $pauses);
        self::assertEqualsWithDelta(3600, $pauses[0]['duration'], 1);
    }

    public function testSharedServiceRequestsArePacedAcrossGeocoderInstances(): void
    {
        $this->respond(['features' => [['geometry' => ['coordinates' => [13, 52]]]]]);
        $this->respond(['features' => [['geometry' => ['coordinates' => [14, 53]]]]]);
        self::assertNotNull($this->geocoder->locate('First address', 'photon', ''));
        $otherGeocoder = new Geocoder(['client' => $this->geocoder->client, 'mutex' => $this->mutex]);
        self::assertNotNull($otherGeocoder->locate('Second address', 'photon', ''));
        self::assertCount(2, $this->transport->requests);
        self::assertGreaterThanOrEqual(0.95, $this->transport->sentAt[1] - $this->transport->sentAt[0]);
    }

    /** @dataProvider providerAttributions */
    public function testProvidersHaveAnAttributionLink(string $provider, string $label, string $url): void
    {
        self::assertSame(['label' => $label, 'url' => $url], Geocoder::getAttribution($provider));
    }

    public static function providerAttributions(): array
    {
        return [
            ['google', 'Geocoding by Google Maps', 'https://maps.google.com/'],
            ['here', 'Geocoding by HERE', 'https://www.here.com/en-gb/terms/general-content-supplier-terms-and-notices'],
            ['locationiq', 'Search by LocationIQ.com', 'https://locationiq.com/'],
            ['opencage', 'Geocoding by OpenCage', 'https://opencagedata.com/'],
            ['photon', 'Geocoding by Photon', 'https://photon.komoot.io/'],
        ];
    }

    public function testCachedErrorsCanBeRenderedFromTheProviderAndStatus(): void
    {
        $this->transport->responses[] = ['headers' => ['http-code' => 403], 'content' => '{}'];
        self::assertNull($this->geocoder->locate('Address', 'here', 'key'));
        $cached = Yii::$app->cache->get(Geocoder::ERROR_CACHE_KEY);
        self::assertSame(['provider' => 'here', 'code' => 'http-error', 'params' => ['status' => 403]], $cached);
        self::assertSame([
            'provider' => 'here',
            'error_message' => 'Geocoding request failed (HTTP 403).',
        ], Geocoder::getLastError());
        self::assertCount(1, $this->transport->requests);
    }

    public function testEncodedCredentialsAreRedactedFromProviderErrors(): void
    {
        $key = 'secret/key section';
        $this->respond(['status' => 'REQUEST_DENIED', 'error_message' => 'Invalid token: ' . rawurlencode($key)]);
        self::assertNull($this->geocoder->locate('Address', 'google', $key));
        self::assertSame(
            'The geocoding provider returned an error: Invalid token: [redacted]',
            Geocoder::getLastError()['error_message'],
        );
        self::assertStringNotContainsString('secret', Json::encode(Yii::$app->cache->get(Geocoder::ERROR_CACHE_KEY)));
    }

    public function testLegacyErrorsAreNotDisplayed(): void
    {
        self::assertFalse(Geocoder::getLastError());
        $legacyError = ['provider' => 'here', 'error_message' => 'Legacy transport diagnostic'];
        Yii::$app->cache->set('usermap.error.cache.lasterrors', $legacyError);
        self::assertFalse(Geocoder::getLastError());

        Yii::$app->cache->set(Geocoder::ERROR_CACHE_KEY, $legacyError);
        self::assertFalse(Geocoder::getLastError());

        Yii::$app->cache->set(Geocoder::ERROR_CACHE_KEY, $legacyError + ['code' => 400]);
        self::assertSame('Unknown geocoding error.', Geocoder::getLastError()['error_message']);
    }

    private function respond(array $data, array $headers = []): void
    {
        $this->transport->responses[] = ['headers' => $headers + ['http-code' => 200], 'content' => Json::encode($data)];
    }
}
