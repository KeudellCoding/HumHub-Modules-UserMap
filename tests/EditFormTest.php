<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\tests;

use humhub\components\SettingsManager;
use humhub\modules\usermap\controllers\AdminController;
use humhub\modules\usermap\models\admin\EditForm;
use humhub\modules\usermap\Module;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\console\Application;
use yii\db\Connection;
use yii\i18n\PhpMessageSource;
use yii\web\Request;

class MemorySettings extends SettingsManager
{
    public array $values = [];
    public array $fixed = [];

    public function init(): void
    {
    }

    public function getFixed(string $name)
    {
        return $this->fixed[$name] ?? null;
    }

    public function getUncached(string $name, $default = null): ?string
    {
        return isset($this->values[$name]) ? (string) $this->values[$name] : $default;
    }

    public function get(string $name, $default = null)
    {
        return $this->fixed[$name] ?? $this->values[$name] ?? $default;
    }

    public function set(string $name, $value): void
    {
        if (!$this->isFixed($name)) {
            $this->values[$name] = $value;
        }
    }

    public function delete(string $name): void
    {
        unset($this->values[$name]);
    }

    public function reload(): void
    {
    }
}

class EditFormTest extends TestCase
{
    private MemorySettings $settings;

    protected function setUp(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transaction')->willReturnCallback(static fn(callable $write) => $write());
        $app = new Application([
            'id' => 'usermap-tests',
            'basePath' => __DIR__,
            'language' => 'en-US',
            'components' => [
                'cache' => ['class' => ArrayCache::class],
                'db' => $db,
                'i18n' => [
                    'translations' => [
                        'UsermapModule.*' => ['class' => PhpMessageSource::class],
                    ],
                ],
            ],
        ]);
        $module = new Module('usermap', $app);
        $this->settings = new MemorySettings();
        $module->set('settings', $this->settings);
        $app->setModule('usermap', $module);
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
    }

    public function testDefaultsCanBeSavedWithoutAGeocodingKey(): void
    {
        $form = new EditForm();
        self::assertFalse($form->hasSavedApiKey());
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        self::assertSame('top_menu', $this->settings->get('map_widget_location'));
        self::assertSame(EditForm::DEFAULT_TILE_SERVER, $this->settings->get('osm_tile_server'));
    }

    public function testConfigurationReadsSkipFormInput(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getIsPost')->willReturn(false);
        $request->expects(self::never())->method('post');
        Yii::$app->set('request', $request);
        $controller = $this->getMockBuilder(AdminController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render'])
            ->getMock();
        $controller->expects(self::once())->method('render')->with('index')->willReturn('configuration');

        self::assertSame('configuration', $controller->actionIndex());
        self::assertSame([], $this->settings->values);
    }

    public function testCustomTileServerIsValidatedAndPersisted(): void
    {
        $form = new EditForm();
        $form->osm_tile_server = 'https://tiles.example.test/{z}/{x}/{y}.png?token=custom';
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        self::assertSame($form->osm_tile_server, (new EditForm())->osm_tile_server);
    }

    /** @dataProvider invalidTiles */
    public function testInvalidTileServerIsRejected($url): void
    {
        $form = new EditForm();
        $form->osm_tile_server = $url;
        self::assertFalse($form->validate());
        self::assertArrayHasKey('osm_tile_server', $form->getErrors());
        self::assertSame([], $this->settings->values);
    }

    public static function invalidTiles(): array
    {
        return [
            ['javascript:alert(1)'], ['https://tiles.example.test/no-placeholders'], [['invalid-array']], [''],
            ['https://tiles.example.test/{style}/{z}/{x}/{y}.png'],
            ['https://tiles.example.test/{z}/{x}/{y}.png?layer={missing'],
            ['https://tiles.example.test/{z}/{x}/{y}}.png'],
        ];
    }

    public function testLeafletSubdomainsRetinaAndInvertedRowsAreSupported(): void
    {
        $form = new EditForm();
        $form->osm_tile_server = 'https://{s}.example.test/{z}/{x}/{-y}{r}.png';
        self::assertTrue($form->save(), json_encode($form->getErrors()));
    }

    public function testCoordinatesZoomAndSortOrderAreBounded(): void
    {
        $form = new EditForm();
        $form->osm_map_center_latitude = 91;
        $form->osm_map_center_longitude = -181;
        $form->osm_map_center_zoom = 20;
        $form->map_widget_location_sort_order = 1.5;
        self::assertFalse($form->save());
        foreach (['osm_map_center_latitude', 'osm_map_center_longitude', 'osm_map_center_zoom', 'map_widget_location_sort_order'] as $attribute) {
            self::assertTrue($form->hasErrors($attribute), $attribute);
        }
        self::assertSame([], $this->settings->values);
    }

    public function testZeroCenterAndZoomAreValid(): void
    {
        $form = new EditForm();
        $form->osm_map_center_latitude = 0;
        $form->osm_map_center_longitude = 0;
        $form->osm_map_center_zoom = 0;
        self::assertTrue($form->save(), json_encode($form->getErrors()));
    }

    /** @dataProvider providersRequiringKeys */
    public function testSelectedProviderRequiresAnApiKey(string $provider): void
    {
        $form = new EditForm();
        $form->geocoding_provider = $provider;
        self::assertFalse($form->save());
        self::assertTrue($form->hasErrors('geocoding_api_key'));
    }

    public static function providersRequiringKeys(): array
    {
        return [['google'], ['here'], ['locationiq'], ['opencage']];
    }

    /** @dataProvider newProviderSettings */
    public function testNewProviderSettingsRoundTrip(string $provider, string $key): void
    {
        $form = new EditForm();
        $form->geocoding_provider = $provider;
        $form->geocoding_api_key = $key;
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        $reloaded = new EditForm();
        self::assertSame($provider, $reloaded->geocoding_provider);
        self::assertSame('', $reloaded->geocoding_api_key);
        $this->assertStoredPair($provider, $key);
    }

    public static function newProviderSettings(): array
    {
        return [['locationiq', 'fake-key'], ['opencage', 'fake-key'], ['photon', '']];
    }

    public function testBlankInputKeepsTheSavedKeyForTheSameProvider(): void
    {
        $this->settings->values = ['geocoding_provider' => 'here', 'geocoding_api_key' => 'saved-key'];
        $form = new EditForm();
        self::assertTrue($form->hasSavedApiKey());
        self::assertSame('', $form->geocoding_api_key);
        $form->geocoding_api_key = ' ';
        $form->osm_map_center_zoom = 8;

        self::assertTrue($form->save(), json_encode($form->getErrors()));
        $this->assertStoredPair('here', 'saved-key');
        self::assertSame(8, $this->settings->get('osm_map_center_zoom'));
    }

    public function testProviderChangeRequiresAnExplicitNewKey(): void
    {
        $saved = ['geocoding_provider' => 'here', 'geocoding_api_key' => 'here-key'];
        $this->settings->values = $saved;
        $form = new EditForm();
        $form->geocoding_provider = 'locationiq';
        self::assertFalse($form->hasSavedApiKey());

        self::assertFalse($form->save());
        self::assertTrue($form->hasErrors('geocoding_api_key'));
        self::assertSame($saved, $this->settings->values);

        $form->geocoding_api_key = 'locationiq-key';
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        $this->assertStoredPair('locationiq', 'locationiq-key');
    }

    public function testSwitchingToPhotonClearsTheStoredKey(): void
    {
        $this->settings->values = ['geocoding_provider' => 'here', 'geocoding_api_key' => 'saved-key'];
        $form = new EditForm();
        $form->geocoding_provider = 'photon';

        self::assertTrue($form->save(), json_encode($form->getErrors()));
        $this->assertStoredPair('photon', '');
    }

    public function testProviderCannotChangeWhileTheKeyIsFixed(): void
    {
        $this->settings->values = ['geocoding_provider' => 'here'];
        $this->settings->fixed = ['geocoding_api_key' => 'fixed-here-key'];
        self::assertSame(['provider' => 'here', 'apiKey' => ''], Yii::$app->getModule('usermap')->getGeocodingConfiguration());
        $form = new EditForm();
        $form->geocoding_provider = 'google';
        $form->geocoding_api_key = 'google-key';

        self::assertFalse($form->save());
        self::assertTrue($form->hasErrors('geocoding_provider'));
        self::assertSame('here', $this->settings->get('geocoding_provider'));
    }

    public function testProviderOverrideNeverInheritsThePreviousProvidersKey(): void
    {
        $this->settings->values = ['geocoding_provider' => 'google', 'geocoding_api_key' => 'google-db'];
        $this->settings->fixed = ['geocoding_provider' => 'here'];
        $module = Yii::$app->getModule('usermap');
        self::assertSame(['provider' => 'here', 'apiKey' => ''], $module->getGeocodingConfiguration());
        $form = new EditForm();
        self::assertFalse($form->save());
        $form->geocoding_api_key = 'here-db';
        self::assertTrue($form->save());
        $this->assertStoredPair('here', 'here-db');
        $this->settings->fixed['geocoding_provider'] = 'google';
        self::assertSame(['provider' => 'google', 'apiKey' => ''], $module->getGeocodingConfiguration());
    }

    public function testFixedLegacyGoogleKeyFollowsRotationAfterAnUnrelatedSave(): void
    {
        $this->settings->fixed = ['google_geocoding_api_key' => 'file-only-first'];
        $form = new EditForm();
        self::assertTrue($form->hasSavedApiKey());
        $form->osm_map_center_zoom = 7;
        self::assertTrue($form->save());
        self::assertStringNotContainsString('file-only', json_encode($this->settings->values));
        $this->settings->fixed['google_geocoding_api_key'] = 'file-only-second';
        self::assertSame(['provider' => 'google', 'apiKey' => 'file-only-second'], Yii::$app->getModule('usermap')->getGeocodingConfiguration());
    }

    public function testFullyFixedCredentialsPreserveTheUnderlyingDatabasePair(): void
    {
        $module = Yii::$app->getModule('usermap');
        $this->settings->values = ['google_geocoding_api_key' => 'google-db'];
        $this->settings->fixed = ['geocoding_provider' => 'here', 'geocoding_api_key' => 'here-file'];
        $form = new EditForm();
        $form->geocoding_api_key = 'override';
        self::assertFalse($form->save());
        $form->geocoding_api_key = '';
        self::assertTrue($form->save());
        self::assertSame(['provider' => 'here', 'apiKey' => 'here-file'], $module->getGeocodingConfiguration());
        self::assertStringNotContainsString('here-file', json_encode($this->settings->values));
        $this->settings->fixed = [];
        $this->assertStoredPair('google', 'google-db');
    }

    public function testLegacyGoogleKeyIsNotUsedByAnotherProvider(): void
    {
        $this->settings->values = ['geocoding_provider' => 'here', 'google_geocoding_api_key' => 'old-google-key'];
        self::assertSame(['provider' => 'here', 'apiKey' => ''], Yii::$app->getModule('usermap')->getGeocodingConfiguration());
        $form = new EditForm();
        self::assertFalse($form->save());
        self::assertTrue($form->hasErrors('geocoding_api_key'));
    }

    public function testUnsupportedSavedProviderIsDisabledAndClearedOnlyOnSave(): void
    {
        $saved = ['geocoding_provider' => 'removed-provider', 'geocoding_api_key' => 'obsolete-key'];
        $this->settings->values = $saved;
        $form = new EditForm();
        self::assertSame('', $form->geocoding_provider);
        self::assertSame('', $form->geocoding_api_key);
        self::assertSame($saved, $this->settings->values);
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        $this->assertStoredPair('', '');
    }

    public function testUnsupportedProviderCannotBeSaved(): void
    {
        $form = new EditForm();
        $form->geocoding_provider = 'removed-provider';
        $form->geocoding_api_key = 'obsolete-key';
        self::assertFalse($form->save());
        self::assertTrue($form->hasErrors('geocoding_provider'));
        self::assertSame([], $this->settings->values);
    }

    public function testLegacySettingsMigrateOnSaveWithoutChangesDuringRead(): void
    {
        $legacy = [
            'map_widget_location' => 'directory_menu',
            'google_geocoding_api_key' => 'fake-legacy-key',
            'osm_tile_server' => 'https://{s}.tile.osm.org/{z}/{x}/{y}.png',
        ];
        $this->settings->values = $legacy;
        $form = new EditForm();
        self::assertSame($legacy, $this->settings->values);
        self::assertSame('top_menu', $form->map_widget_location);
        self::assertSame('google', $form->geocoding_provider);
        self::assertSame('', $form->geocoding_api_key);
        self::assertSame(EditForm::DEFAULT_TILE_SERVER, $form->osm_tile_server);
        self::assertTrue($form->save(), json_encode($form->getErrors()));
        self::assertArrayNotHasKey('google_geocoding_api_key', $this->settings->values);
        $this->assertStoredPair('google', 'fake-legacy-key');
    }

    private function assertStoredPair(string $provider, string $key): void
    {
        $pair = ['provider' => $provider, 'apiKey' => $key];
        self::assertSame($pair, $this->settings->getSerialized('geocoding_configuration'));
        self::assertSame($pair, Yii::$app->getModule('usermap')->getGeocodingConfiguration());
        self::assertArrayNotHasKey('geocoding_provider', $this->settings->values);
        self::assertArrayNotHasKey('geocoding_api_key', $this->settings->values);
        self::assertArrayNotHasKey('google_geocoding_api_key', $this->settings->values);
    }
}
