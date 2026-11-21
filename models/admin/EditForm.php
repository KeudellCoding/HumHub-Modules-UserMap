<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\models\admin;

use humhub\modules\usermap\components\Geocoder;
use humhub\modules\usermap\components\GeocodingSettings;
use Yii;
use yii\base\Model;
use yii\db\Exception as DbException;

class EditForm extends Model
{
    public const DEFAULT_TILE_SERVER = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    private const LEGACY_TILE_SERVERS = [
        'https://{s}.tile.osm.org/{z}/{x}/{y}.png',
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    ];

    public $map_widget_location;
    public $map_widget_location_sort_order;
    public $osm_tile_server;
    public $osm_map_center_latitude;
    public $osm_map_center_longitude;
    public $osm_map_center_zoom;
    public $geocoding_provider;
    public $geocoding_api_key = '';
    public $geocoding_use_street;

    private string $savedProvider;
    private bool $savedKeyExists;
    private GeocodingSettings $geocoding;

    public function rules()
    {
        return [
            [['osm_tile_server', 'geocoding_api_key'], 'trim', 'skipOnArray' => true, 'skipOnEmpty' => true],
            ['osm_tile_server', 'string'],
            ['geocoding_api_key', 'string', 'skipOnEmpty' => false],
            [
                [
                    'map_widget_location', 'map_widget_location_sort_order', 'osm_tile_server',
                    'osm_map_center_latitude', 'osm_map_center_longitude', 'osm_map_center_zoom',
                ],
                'required',
            ],
            ['map_widget_location', 'in', 'range' => array_keys(self::getWidgetLocations())],
            ['map_widget_location_sort_order', 'integer', 'min' => 0],
            ['osm_map_center_zoom', 'integer', 'min' => 0, 'max' => 19],
            ['osm_map_center_latitude', 'number', 'min' => -90, 'max' => 90],
            ['osm_map_center_longitude', 'number', 'min' => -180, 'max' => 180],
            ['osm_tile_server', 'validateTileServer'],
            ['geocoding_provider', 'in', 'range' => array_keys(self::getProviders()), 'strict' => true, 'skipOnEmpty' => false],
            ['geocoding_provider', 'validateProviderChange', 'skipOnEmpty' => false],
            ['geocoding_api_key', 'validateFixedKey'],
            [
                'geocoding_api_key',
                'required',
                'when' => fn() => is_string($this->geocoding_provider)
                    && Geocoder::requiresApiKey($this->geocoding_provider)
                    && !$this->isApiKeyFixed()
                    && !$this->hasSavedApiKey(),
            ],
            ['geocoding_use_street', 'boolean'],
        ];
    }

    public function init()
    {
        parent::init();
        $module = Yii::$app->getModule('usermap');
        $settings = $module->settings;

        $this->map_widget_location = $module->getMapWidgetLocation();
        $this->map_widget_location_sort_order = $settings->get('map_widget_location_sort_order', 400);
        $this->osm_tile_server = self::normalizeTileServer($settings->get('osm_tile_server'));
        $this->osm_map_center_latitude = $settings->get('osm_map_center_latitude', 51.0951);
        $this->osm_map_center_longitude = $settings->get('osm_map_center_longitude', 10.2714);
        $this->osm_map_center_zoom = $settings->get('osm_map_center_zoom', 5);

        $this->geocoding = new GeocodingSettings($settings);
        ['provider' => $provider, 'apiKey' => $apiKey] = $this->geocoding->read();
        $this->savedProvider = array_key_exists($provider, self::getProviders()) ? $provider : '';
        $this->savedKeyExists = $apiKey !== '';
        $this->geocoding_provider = $this->savedProvider;
        $this->geocoding_use_street = $settings->get('geocoding_use_street', true);
    }

    public function hasSavedApiKey(): bool
    {
        return $this->geocoding_provider === $this->savedProvider && $this->savedKeyExists;
    }

    public function isProviderFixed(): bool
    {
        return $this->geocoding->isProviderFixed();
    }

    public function isApiKeyFixed(): bool
    {
        return is_string($this->geocoding_provider) && $this->geocoding->isKeyFixed($this->geocoding_provider);
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $settings = Yii::$app->getModule('usermap')->settings;
        $values = $this->getAttributes(null, ['geocoding_provider', 'geocoding_api_key']);
        $values['geocoding_use_street'] = (bool) $this->geocoding_use_street;
        try {
            // Commit the provider and its key together.
            Yii::$app->db->transaction(function () use ($settings, $values): void {
                foreach ($values as $attribute => $value) {
                    $settings->set($attribute, $value);
                }
                $this->geocoding->save($this->geocoding_provider, $this->geocoding_api_key);
            });
        } catch (DbException $error) {
            Yii::error('Could not save Usermap settings (' . get_class($error) . ').', 'usermap');
            $this->addError('geocoding_provider', Yii::t('UsermapModule.base', 'Could not save the module settings.'));
            return false;
        } finally {
            // HumHub also keeps a settings snapshot in memory.
            $settings->reload();
        }

        return true;
    }

    public function validateProviderChange(string $attribute): void
    {
        if ($this->geocoding_provider !== $this->savedProvider && $this->isProviderFixed()) {
            $this->addError($attribute, Yii::t('UsermapModule.base', 'Change the provider and its key in the HumHub configuration.'));
        }
    }

    public function validateFixedKey(string $attribute): void
    {
        if ($this->isApiKeyFixed()) {
            $this->addError($attribute, Yii::t('UsermapModule.base', 'Change this API key in the HumHub configuration.'));
        }
    }

    public function validateTileServer(string $attribute): void
    {
        $error = self::tileServerError($this->$attribute);
        if ($error !== null) {
            $this->addError($attribute, $error);
        }
    }

    public static function tileServerError(string $template): ?string
    {
        $url = strtr($template, ['{s}' => 'a', '{z}' => '0', '{x}' => '0', '{y}' => '0', '{r}' => '', '{-y}' => '0']);
        if (preg_match('/\{[^{}]*\}|[{}]/', $url, $unknown)) {
            return Yii::t('UsermapModule.base', 'Unsupported tile URL placeholder {placeholder}.', ['placeholder' => $unknown[0]]);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || !str_contains($template, '{z}')
            || !str_contains($template, '{x}')
            || (!str_contains($template, '{y}') && !str_contains($template, '{-y}'))) {
            return Yii::t('UsermapModule.base', 'Enter an HTTP or HTTPS tile URL containing {z}, {x} and {y} or {-y}.');
        }
        return null;
    }

    public static function normalizeTileServer(?string $url): string
    {
        return empty($url) || in_array($url, self::LEGACY_TILE_SERVERS, true) ? self::DEFAULT_TILE_SERVER : $url;
    }

    public function attributeLabels()
    {
        return [
            'map_widget_location' => Yii::t('UsermapModule.base', 'Map location'),
            'map_widget_location_sort_order' => Yii::t('UsermapModule.base', 'Sort order'),
            'osm_tile_server' => Yii::t('UsermapModule.base', 'Tile server URL'),
            'osm_map_center_latitude' => Yii::t('UsermapModule.base', 'Default latitude'),
            'osm_map_center_longitude' => Yii::t('UsermapModule.base', 'Default longitude'),
            'osm_map_center_zoom' => Yii::t('UsermapModule.base', 'Default zoom level'),
            'geocoding_provider' => Yii::t('UsermapModule.base', 'Geocoding provider'),
            'geocoding_api_key' => Yii::t('UsermapModule.base', 'Geocoding API key'),
            'geocoding_use_street' => Yii::t('UsermapModule.base', 'Use streets in geocoding'),
        ];
    }

    public function attributeHints()
    {
        return [
            'osm_tile_server' => Yii::t('UsermapModule.base', 'Use {z}, {x} and either {y} or {-y}. The optional placeholders are {s} for the subdomain and {r} for the retina suffix.'),
            'osm_map_center_latitude' => Yii::t('UsermapModule.base', 'Enter a decimal number with a dot as separator.'),
            'osm_map_center_longitude' => Yii::t('UsermapModule.base', 'Enter a decimal number with a dot as separator.'),
            'geocoding_provider' => Yii::t('UsermapModule.base', 'Choose a service to turn profile addresses into map markers. Photon is a shared demo service for small request volumes. OpenCage offers a free trial for testing.'),
            'geocoding_api_key' => $this->isProviderFixed() && $this->isApiKeyFixed()
                ? Yii::t('UsermapModule.base', 'Manage these credentials in the HumHub configuration. A fixed geocoding_api_key requires a matching fixed geocoding_provider.')
                : Yii::t('UsermapModule.base', 'Leave blank to keep the saved key for the current provider. Enter the key for the new provider if it requires one.'),
        ];
    }

    public static function getProviders(): array
    {
        return [
            '' => Yii::t('UsermapModule.base', 'No geocoding'),
            'google' => 'Google',
            'here' => 'HERE',
            'locationiq' => 'LocationIQ',
            'opencage' => 'OpenCage',
            'photon' => 'Photon (Komoot)',
        ];
    }

    public static function getWidgetLocations(): array
    {
        return [
            'dashboard_sidebar' => Yii::t('UsermapModule.base', 'Dashboard sidebar'),
            'top_menu' => Yii::t('UsermapModule.base', 'Top menu'),
        ];
    }
}
