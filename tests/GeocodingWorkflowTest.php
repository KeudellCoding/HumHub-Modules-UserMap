<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\tests;

use ArrayIterator;
use humhub\components\SettingsManager;
use humhub\modules\queue\driver\Instant;
use humhub\modules\queue\Events as QueueEvents;
use humhub\modules\user\components\ActiveQueryUser;
use humhub\modules\user\components\PermissionManager;
use humhub\modules\user\models\User;
use humhub\modules\user\permissions\ViewAboutPage;
use humhub\modules\usermap\components\Geocoder;
use humhub\modules\usermap\controllers\MapController;
use humhub\modules\usermap\Events;
use humhub\modules\usermap\Module;
use humhub\modules\usermap\widgets\MapView;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\db\Connection;
use yii\httpclient\Client;
use yii\httpclient\Transport;
use yii\i18n\PhpMessageSource;
use yii\mutex\Mutex;
use yii\queue\db\Queue;
use yii\web\Application;
use yii\web\Request;
use yii\web\Session;
use yii\web\User as WebUser;

class GeocodingWorkflowTest extends TestCase
{
    private Module $module;
    private User $viewer;
    private array $members;
    private array $addresses = [2 => 'Fixture address', 3 => 'Fixture address'];
    private array $allowed = [2 => true, 3 => true];
    private Transport $transport;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated migration and queue tests use PDO SQLite.');
        }
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getMethod'])->getMock();
        $request->method('getMethod')->willReturn('POST');
        $request->setScriptUrl('/index.php');
        $request->cookieValidationKey = 'fixture';
        $mutex = $this->createMock(Mutex::class);
        $mutex->method('acquire')->willReturn(true);
        $mutex->method('release')->willReturn(true);
        $app = new Application([
            'id' => 'usermap-workflow-test',
            'basePath' => __DIR__,
            'language' => 'en-US',
            'components' => [
                'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
                'cache' => ArrayCache::class, 'runtimeCache' => ArrayCache::class,
                'mutex' => $mutex, 'queue' => Queue::class, 'request' => $request,
                'user' => ['class' => WebUser::class, 'identityClass' => User::class, 'enableSession' => false],
                'session' => $this->createMock(Session::class),
                'i18n' => ['translations' => ['UsermapModule.*' => ['class' => PhpMessageSource::class]]],
            ],
        ]);
        $app->db->createCommand('CREATE TABLE setting (id INTEGER PRIMARY KEY, module_id TEXT, name TEXT, value TEXT, UNIQUE(module_id, name))')->execute();
        $app->db->createCommand('CREATE TABLE queue (id INTEGER PRIMARY KEY, channel TEXT, job BLOB, pushed_at INTEGER, ttr INTEGER, delay INTEGER, priority INTEGER, reserved_at INTEGER, done_at INTEGER, attempt INTEGER)')->execute();
        $app->db->createCommand('CREATE TABLE queue_exclusive (id TEXT PRIMARY KEY, job_message_id TEXT, job_status INTEGER DEFAULT 2)')->execute();
        $app->queue->on(Queue::EVENT_BEFORE_PUSH, [QueueEvents::class, 'onQueueBeforePush']);
        $app->queue->on(Queue::EVENT_AFTER_PUSH, [QueueEvents::class, 'onQueueAfterPush']);
        $app->queue->on(Queue::EVENT_AFTER_EXEC, [Events::class, 'onQueueAfterExec']);
        $this->module = new Module('usermap', $app);
        $this->module->set('settings', new SettingsManager(['moduleId' => 'usermap']));
        $app->setModule('usermap', $this->module);

        $this->viewer = $this->user(1);
        $app->user->setIdentity($this->viewer);
        $this->members = [2 => $this->user(2), 3 => $this->user(3)];
        Yii::$container->set(ActiveQueryUser::class, fn() => $this->userQuery());
        $this->module->getFormatedAddressCallback = fn(User $user) => $this->addresses[$user->id];
        $this->transport = new class extends Transport {
            public int $calls = 0;
            public function send($request)
            {
                $this->calls++;
                return $request->client->createResponse(
                    '{"status":"OK","results":[{"geometry":{"location":{"lat":0,"lng":13}}}],"items":[{"position":{"lat":0,"lng":13}}]}',
                    ['http-code' => 200, 'cache-control' => 'private, no-store'],
                );
            }
        };
        Yii::$container->set(Geocoder::class, new Geocoder(['client' => new Client(['transport' => $this->transport])]));
    }

    protected function tearDown(): void
    {
        Yii::$container->clear(ActiveQueryUser::class);
        Yii::$container->clear(Geocoder::class);
        Yii::$app = null;
    }

    public function testColdPagesShareOneJobAndTheWorkerMakesMarkersAvailable(): void
    {
        $this->configure('google');
        self::assertSame([], $this->map()['mapOptions']['users']);
        self::assertTrue($this->map()['hasQueuedLookups']);
        self::assertSame(0, $this->transport->calls);
        $jobs = Yii::$app->db->createCommand('SELECT job FROM queue')->queryColumn();
        self::assertCount(1, $jobs);
        self::assertStringNotContainsString('fixture-key', $jobs[0]);
        self::assertStringNotContainsString('Fixture address', $jobs[0]);

        Yii::$app->user->setIdentity(null);
        Yii::$app->language = 'de';
        Yii::$app->queue->run(false);
        self::assertNull(Yii::$app->user->getIdentity());
        self::assertSame('de', Yii::$app->language);
        self::assertSame(1, $this->transport->calls);
        self::assertSame(0, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM queue_exclusive')->queryScalar());

        Yii::$app->user->setIdentity($this->viewer);
        $map = $this->map();
        self::assertFalse($map['hasQueuedLookups']);
        self::assertCount(2, $map['mapOptions']['users']);
        self::assertSame(['coords' => ['latitude' => 0.0, 'longitude' => 13.0]], $map['mapOptions']['users'][0]);
        self::assertSame(1, $this->transport->calls);
    }

    public function testPollingStreamsCompletedMarkersAndStopsAfterTheLastJob(): void
    {
        $this->configure('google');
        $this->addresses[3] = 'Another address';
        $token = $this->map()['mapOptions']['updatesToken'];
        $controller = new MapController('map', $this->module);
        $poll = static function ($token) use ($controller) {
            Yii::$app->request->setBodyParams(['token' => $token]);
            return $controller->actionUpdates()->data;
        };
        self::assertSame(['users' => [], 'next' => $token], $poll($token));
        Yii::$app->user->setIdentity($this->members[3]);
        self::assertSame(['users' => [], 'next' => null], $poll($token));
        Yii::$app->user->setIdentity($this->viewer);
        $coords = ['latitude' => 1.0, 'longitude' => 2.0];
        Yii::$app->cache->set(['usermap.geocoding.v3', 'google', hash('sha256', 'fixture-key'), 'Fixture address'], $coords);
        $partial = $poll($token);
        self::assertSame([['coords' => $coords]], $partial['users']);
        self::assertNotNull($partial['next']);
        self::assertSame(0, $this->transport->calls);
        Yii::$app->queue->run(false);
        $last = $poll($partial['next']);
        self::assertCount(1, $last['users']);
        self::assertNull($last['next']);
    }

    public function testFinishedQueueWithStaleReservationEndsPolling(): void
    {
        $this->configure('google');
        $token = $this->map()['mapOptions']['updatesToken'];
        Yii::$app->db->createCommand()->delete('queue')->execute();
        Yii::$app->request->setBodyParams(['token' => $token]);
        $controller = new MapController('map', $this->module);
        self::assertSame(['users' => [], 'next' => null], $controller->actionUpdates()->data);
    }

    public function testOneHundredColdAddressesRenderWithoutCallingTheProvider(): void
    {
        $this->configure('photon');
        $this->members = [];
        for ($id = 2; $id < 102; $id++) {
            $this->members[$id] = $this->user($id);
            $this->allowed[$id] = true;
            $this->addresses[$id] = 'Fixture address ' . $id;
        }
        $map = $this->map(false);
        self::assertTrue($map['hasQueuedLookups']);
        self::assertSame([], $map['mapOptions']['users']);
        self::assertSame(100, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM queue')->queryScalar());
        self::assertSame(0, $this->transport->calls);
    }

    /** @dataProvider changesBeforeExecution */
    public function testWorkerDropsObsoleteLookups(string $change): void
    {
        $this->configure('google');
        self::assertTrue($this->map()['hasQueuedLookups']);
        switch ($change) {
            case 'permission':
                $this->allowed[2] = false;
                break;
            case 'address':
                $this->addresses[2] = 'Changed address';
                break;
            case 'key':
                // Simulate another process rotating the key while the worker's settings are loaded.
                (new SettingsManager(['moduleId' => 'usermap']))->setSerialized('geocoding_configuration', [
                    'provider' => 'google', 'apiKey' => 'rotated-key',
                ]);
                break;
        }
        Yii::$app->queue->run(false);
        self::assertSame(0, $this->transport->calls);
        self::assertSame(0, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM queue')->queryScalar());
    }

    public static function changesBeforeExecution(): array
    {
        return [['permission'], ['address'], ['key']];
    }

    /** @dataProvider liveLookups */
    public function testDeferredLookupsAreViewerBoundSingleUseAndRecheckPermissions(string $provider, bool $preview): void
    {
        $this->configure($provider);
        if ($provider === 'google') {
            Yii::$app->set('queue', Instant::class);
        }
        $options = $this->map($preview)['mapOptions'];
        self::assertSame([], $options['users']);
        self::assertCount(1, $options['lookupTokens']);
        self::assertSame(0, $this->transport->calls);
        $token = $options['lookupTokens'][0];
        $this->allowed[3] = false;

        Yii::$app->request->setBodyParams(['token' => $token]);
        $controller = new MapController('map', $this->module);
        Yii::$app->user->setIdentity($this->members[3]);
        self::assertSame([], $controller->actionLookup()->data['users']);
        Yii::$app->user->setIdentity($this->viewer);
        $response = $controller->actionLookup();
        self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
        self::assertCount(1, $response->data['users']);
        $expected = ['coords' => ['latitude' => 0.0, 'longitude' => 13.0]];
        if (!$preview) {
            $expected += ['displayname' => 'Member 2', 'profileurl' => '/user/2', 'formatedaddress' => 'Fixture address'];
        }
        self::assertSame($expected, $response->data['users'][0]);
        self::assertSame([], $controller->actionLookup()->data['users']);
        self::assertSame(1, $this->transport->calls);
        $nextPage = $this->map($preview)['mapOptions'];
        if ($provider === 'here') {
            self::assertSame([], $nextPage['users']);
            self::assertNotSame($token, $nextPage['lookupTokens'][0]);
        } else {
            self::assertSame([$expected], $nextPage['users']);
            self::assertArrayNotHasKey('lookupTokens', $nextPage);
        }
    }

    public static function liveLookups(): array
    {
        return [['here', true], ['here', false], ['google', true]];
    }

    public function testUpgradeMigratesOnlyStoredCredentialsAndPurgesTheLegacyDiagnostic(): void
    {
        $this->module->settings->set('google_geocoding_api_key', 'google-db');
        Yii::$app->params['fixed-settings']['usermap']['google_geocoding_api_key'] = 'google-file';
        Yii::$app->cache->set('usermap.error.cache.lasterrors', ['message' => 'legacy secret']);
        require_once dirname(__DIR__) . '/migrations/m261121_000001_geocoding_settings.php';
        $migration = new \m261121_000001_geocoding_settings();
        $migration->up();
        $migration->up();
        $this->module->settings->reload();
        self::assertFalse(Yii::$app->cache->get('usermap.error.cache.lasterrors'));
        self::assertNull($this->module->settings->getUncached('google_geocoding_api_key'));
        self::assertSame(['provider' => 'google', 'apiKey' => 'google-db'], $this->module->settings->getSerialized('geocoding_configuration'));
        self::assertSame('google-file', $this->module->getGeocodingConfiguration()['apiKey']);
        Yii::$app->params['fixed-settings']['usermap']['google_geocoding_api_key'] = 'rotated-file';
        self::assertSame('rotated-file', $this->module->getGeocodingConfiguration()['apiKey']);
    }

    public function testInvalidStoredTileUrlLeavesTheMapUsableWithoutRequestingTiles(): void
    {
        $this->module->settings->set('osm_tile_server', 'https://tiles.example.test/{style}/{z}/{x}/{y}');
        self::assertNull($this->map()['mapOptions']['tileServer']);
    }

    private function configure(string $provider): void
    {
        $this->module->settings->setSerialized('geocoding_configuration', ['provider' => $provider, 'apiKey' => 'fixture-key']);
    }

    private function map(bool $preview = true): array
    {
        $widget = new class extends MapView {
            public function render($view, $params = [])
            {
                return $params;
            }
        };
        $widget->link = $preview ? '/usermap/map' : null;
        return $widget->run();
    }

    private function user(int $id): User
    {
        $user = $this->getMockBuilder(User::class)->onlyMethods(['attributes', 'getPermissionManager', 'getDisplayName', 'getUrl'])->getMock();
        $user->method('attributes')->willReturn(['id']);
        $user->setAttribute('id', $id);
        $user->method('getDisplayName')->willReturn('Member ' . $id);
        $user->method('getUrl')->willReturn('/user/' . $id);
        $permissions = $this->createMock(PermissionManager::class);
        $permissions->method('can')->with(ViewAboutPage::class)->willReturnCallback(fn() => $this->allowed[$id] ?? false);
        $user->method('getPermissionManager')->willReturn($permissions);
        return $user;
    }

    private function userQuery(): ActiveQueryUser
    {
        $ids = null;
        $query = $this->createMock(ActiveQueryUser::class);
        $query->method('active')->willReturnSelf();
        $query->method('available')->with($this->viewer)->willReturnSelf();
        $query->method('with')->with('profile')->willReturnSelf();
        $query->method('andWhere')->willReturnCallback(static function (array $condition) use (&$ids, $query) {
            $ids = (array) $condition['user.id'];
            return $query;
        });
        $query->method('one')->willReturnCallback(function () use (&$ids) {
            return $ids === [1] ? $this->viewer : null;
        });
        $query->method('each')->willReturnCallback(function () use (&$ids) {
            return new ArrayIterator($ids === null ? $this->members : array_intersect_key($this->members, array_flip($ids)));
        });
        return $query;
    }
}
