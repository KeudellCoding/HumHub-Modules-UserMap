<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\components;

use humhub\components\SettingsManager;
use yii\db\Exception;

class GeocodingSettings
{
    private const SETTING = 'geocoding_configuration';
    private const LEGACY_SETTINGS = ['geocoding_provider', 'geocoding_api_key', 'google_geocoding_api_key'];

    public function __construct(private SettingsManager $settings)
    {
    }

    /** @return array{provider: string, apiKey: string} */
    public function read(): array
    {
        $stored = $this->settings->getSerialized(self::SETTING);
        if ($stored === null) {
            $stored = $this->legacyConfiguration();
        }

        $fixedProvider = $this->settings->getFixed('geocoding_provider');
        $fixedKey = $this->settings->getFixed('geocoding_api_key');
        $fixedGoogleKey = $this->settings->getFixed('google_geocoding_api_key');
        $provider = $fixedProvider ?? $stored['provider'] ?? ($fixedGoogleKey ? 'google' : '');
        $provider = is_string($provider) ? $provider : '';
        $key = '';

        if (Geocoder::requiresApiKey($provider)) {
            if ($fixedKey !== null) {
                // A generic fixed key has no provider identity of its own.
                $key = $fixedProvider === $provider ? $fixedKey : '';
            } elseif ($provider === 'google' && $fixedGoogleKey !== null) {
                $key = $fixedGoogleKey;
            } elseif (($stored['provider'] ?? null) === $provider) {
                $key = $stored['apiKey'] ?? '';
            }
        }

        return ['provider' => $provider, 'apiKey' => is_scalar($key) ? trim((string) $key) : ''];
    }

    public function isProviderFixed(): bool
    {
        return $this->settings->isFixed(self::SETTING) || $this->settings->isFixed('geocoding_provider')
            || $this->settings->isFixed('geocoding_api_key');
    }

    public function isKeyFixed(string $provider): bool
    {
        return $this->settings->isFixed(self::SETTING) || $this->settings->isFixed('geocoding_api_key')
            || ($provider === 'google' && $this->settings->isFixed('google_geocoding_api_key'));
    }

    /** Called inside the settings transaction. */
    public function save(string $provider, string $inputKey): void
    {
        $this->migrate();
        if ($this->isProviderFixed() && $this->isKeyFixed($provider)) {
            return;
        }

        $stored = $this->settings->getSerialized(self::SETTING);
        $key = '';
        if (Geocoder::requiresApiKey($provider)) {
            $key = ($stored['provider'] ?? null) === $provider ? ($stored['apiKey'] ?? '') : '';
            if ($inputKey !== '' && !$this->isKeyFixed($provider)) {
                $key = $inputKey;
            }
        }
        $pair = ['provider' => $provider, 'apiKey' => $key];
        if ($stored !== $pair) {
            $this->settings->setSerialized(self::SETTING, $pair);
        }
    }

    /** Migrate only database values, independently of any file-based overrides. */
    public function migrate(): void
    {
        if ($this->settings->getUncached(self::SETTING) === null) {
            $legacy = $this->legacyConfiguration(true);
            if ($legacy !== null) {
                if ($this->settings->isFixed(self::SETTING)) {
                    return;
                }
                $this->settings->setSerialized(self::SETTING, $legacy);
                if ($this->settings->getUncached(self::SETTING) === null) {
                    throw new Exception('Could not migrate the Usermap geocoding settings.');
                }
            }
        }
        foreach (self::LEGACY_SETTINGS as $name) {
            if ($this->settings->getUncached($name) !== null) {
                $this->settings->delete($name);
            }
        }
    }

    private function legacyConfiguration(bool $databaseOnly = false): ?array
    {
        // get() includes fixed settings. Only a provider and key from the same source form a pair.
        $stored = [];
        foreach (self::LEGACY_SETTINGS as $name) {
            $stored[$name] = $databaseOnly || $this->settings->isFixed($name)
                ? $this->settings->getUncached($name)
                : $this->settings->get($name);
        }
        $provider = $stored['geocoding_provider'] ?? ($stored['google_geocoding_api_key'] ? 'google' : null);
        if ($provider === null) {
            return null;
        }
        $key = $stored['geocoding_provider'] === $provider ? $stored['geocoding_api_key'] : null;
        $key ??= $provider === 'google' ? $stored['google_geocoding_api_key'] : '';
        return ['provider' => (string) $provider, 'apiKey' => Geocoder::requiresApiKey((string) $provider) ? (string) $key : ''];
    }
}
