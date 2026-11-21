<?php

/**
 * User Location Map
 *
 * @package humhub.modules.usermap
 * @author KeudellCoding
 */

namespace humhub\modules\usermap\tests;

use ArrayIterator;
use DOMDocument;
use DOMElement;
use humhub\components\SettingsManager;
use humhub\modules\user\components\ActiveQueryUser;
use humhub\modules\user\components\PermissionManager;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use humhub\modules\user\permissions\ViewAboutPage;
use humhub\modules\usermap\assets\MapAssetBundle;
use humhub\modules\usermap\components\MapMembers;
use humhub\modules\usermap\Module;
use humhub\modules\usermap\widgets\MapView;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\helpers\Json;
use yii\i18n\PhpMessageSource;
use yii\web\User as WebUser;
use yii\web\View;

class MapViewTest extends TestCase
{
    protected function tearDown(): void
    {
        Yii::$app = null;
    }

    public function testProfileDataRoundTripsThroughTheMapOptionsAttribute(): void
    {
        $options = ['users' => [[
            'displayname' => '<em title="Name">Jörg O\'Connor & Co.</em>',
            'formatedaddress' => '10115 <em title="City">Berlin</em> & Umgebung',
            'profileurl' => '/user/profile?name=fixture&view=about',
            'coords' => ['latitude' => 0, 'longitude' => 0],
        ]]];
        $widget = $this->renderMapView($options);

        self::assertSame('usermap.UserMap', $widget->getAttribute('data-ui-widget'));
        self::assertTrue($widget->hasAttribute('data-ui-init'));
        self::assertSame($options, Json::decode($widget->getAttribute('data-map-options')));
        self::assertSame(0, $widget->ownerDocument->getElementsByTagName('em')->length);
        self::assertSame(0, $widget->getElementsByTagName('a')->length);
    }

    public function testPreviewLinksFromTheMapCanvas(): void
    {
        $widget = $this->renderMapView(['users' => []], '/usermap/map');

        $links = $widget->getElementsByTagName('a');
        self::assertSame(1, $links->length);
        $link = $links->item(0);
        self::assertSame('usermap-map', $link->parentNode->getAttribute('class'));
        self::assertSame('usermap-preview-link', $link->getAttribute('class'));
        self::assertSame('/usermap/map', $link->getAttribute('href'));
        self::assertSame('Open map', $link->getAttribute('aria-label'));
        self::assertSame('', $link->textContent);
    }

    /** @dataProvider profileAddresses */
    public function testDefaultProfileAddressFormatting(array $attributes, bool $includeStreet, ?string $expected, ?array $visibleFields = null): void
    {
        $app = new Application(['id' => 'usermap-address-test', 'basePath' => __DIR__]);

        $settings = $this->createMock(SettingsManager::class);
        $settings->method('get')->with('geocoding_use_street', true)->willReturn($includeStreet);
        $module = new Module('usermap', $app);
        $module->set('settings', $settings);
        $app->setModule('usermap', $module);

        $profile = $this->getMockBuilder(Profile::class)->onlyMethods(['attributes'])->getMock();
        $profile->method('attributes')->willReturn(array_keys($attributes));
        foreach ($attributes as $name => $value) {
            $profile->setAttribute($name, $value);
        }
        $user = $this->getMockBuilder(User::class)->onlyMethods(['attributes'])->getMock();
        $user->method('attributes')->willReturn([]);
        $user->populateRelation('profile', $profile);

        $formatter = new class extends MapMembers {
            public array $visibleFields;

            protected function getVisibleAddressFields(): array
            {
                return $this->visibleFields;
            }

        };
        $formatter->visibleFields = $visibleFields ?? array_keys($attributes);
        self::assertSame($expected, $formatter->getFormatedAddress($user));
    }

    public static function profileAddresses(): array
    {
        return [
            'ZIP code and ISO country code' => [['zip' => ' 10115 ', 'country' => ' de '], true, '10115, Germany'],
            'city and country name' => [['zip' => ' ', 'city' => ' Berlin ', 'country' => ' Germany '], true, 'Berlin, Germany'],
            'ZIP code without country' => [['zip' => '10115'], true, null],
            'city without country' => [['city' => 'Berlin', 'country' => ' '], true, null],
            'street and country alone' => [['street' => 'Invalidenstraße 116', 'country' => 'DE'], true, null],
            'street included with partial address' => [['street' => ' Invalidenstraße 116 ', 'city' => 'Berlin', 'country' => 'DE'], true, 'Invalidenstraße 116, Berlin, Germany'],
            'street excluded with partial address' => [['street' => 'Invalidenstraße 116', 'city' => 'Berlin', 'country' => 'DE'], false, 'Berlin, Germany'],
            'ZIP code and city without country' => [['zip' => '10115', 'city' => 'Berlin'], true, '10115 Berlin'],
            'full address' => [['street' => 'Invalidenstraße 116', 'zip' => '10115', 'city' => 'Berlin', 'country' => 'DE'], true, 'Invalidenstraße 116, 10115 Berlin, DE'],
            'hidden street' => [['street' => 'Invalidenstraße 116', 'zip' => '10115', 'city' => 'Berlin'], true, '10115 Berlin', ['zip', 'city']],
            'hidden locality' => [['zip' => '10115', 'city' => 'Berlin', 'country' => 'DE'], true, null, ['country']],
            'hidden ZIP code and visible city and country' => [['zip' => '10115', 'city' => 'Berlin', 'country' => 'DE'], true, 'Berlin, Germany', ['city', 'country']],
        ];
    }

    public function testOnlyAuthorizedMembersContributeMarkersAndPreviewUsesCoordinates(): void
    {
        $app = new Application([
            'id' => 'usermap-privacy-test',
            'basePath' => __DIR__,
            'components' => [
                'user' => ['class' => WebUser::class, 'identityClass' => User::class, 'enableSession' => false],
            ],
        ]);
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('get')->willReturnCallback(static fn(string $name, $default = null) => $default);
        $module = new Module('usermap', $app);
        $module->set('settings', $settings);
        $app->setModule('usermap', $module);
        $viewer = new User();
        $app->user->setIdentity($viewer);

        $users = [];
        foreach ([false, true] as $canView) {
            $permissionManager = $this->createMock(PermissionManager::class);
            $permissionManager->expects(self::once())->method('can')->with(ViewAboutPage::class)->willReturn($canView);
            $user = $this->createMock(User::class);
            $user->expects(self::once())->method('getPermissionManager')->with($viewer)->willReturn($permissionManager);
            $users[] = $user;
        }
        $query = $this->createMock(ActiveQueryUser::class);
        $query->expects(self::once())->method('available')->with($viewer)->willReturnSelf();
        $query->method('with')->with('profile')->willReturnSelf();
        $query->method('each')->with(100)->willReturn(new ArrayIterator($users));
        Yii::$container->set(ActiveQueryUser::class, static fn() => $query);

        $formattedUsers = [];
        $locatedUsers = [];
        $module->getFormatedAddressCallback = static function (User $user) use (&$formattedUsers): string {
            $formattedUsers[] = $user;
            return 'Fixture address';
        };
        $module->getCoordinatesCallback = static function (User $user) use (&$locatedUsers): array {
            $locatedUsers[] = $user;
            return ['latitude' => 0, 'longitude' => 0];
        };
        $widget = new class (['link' => '/usermap/map']) extends MapView {
            public function render($view, $params = [])
            {
                return $params['mapOptions']['users'];
            }
        };

        try {
            self::assertSame([['coords' => ['latitude' => 0.0, 'longitude' => 0.0]]], $widget->run());
            self::assertSame([$users[1]], $formattedUsers);
            self::assertSame([$users[1]], $locatedUsers);
        } finally {
            Yii::$container->clear(ActiveQueryUser::class);
        }
    }

    private function renderMapView(array $options, ?string $link = null): DOMElement
    {
        new Application([
            'id' => 'usermap-view-test',
            'basePath' => __DIR__,
            'components' => [
                'i18n' => [
                    'translations' => [
                        'UsermapModule.base' => ['class' => PhpMessageSource::class],
                    ],
                ],
            ],
        ]);
        $view = $this->getMockBuilder(View::class)->onlyMethods(['registerAssetBundle'])->getMock();
        $view->expects(self::once())->method('registerAssetBundle')->with(MapAssetBundle::class);

        $html = $view->renderFile('@usermap/widgets/views/mapView.php', [
            'id' => 'usermap-test',
            'height' => '20em',
            'link' => $link,
            'showAsPanel' => false,
            'hasQueuedLookups' => false,
            'mapOptions' => $options,
        ]);

        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_NONET));
        $widget = $document->getElementById('usermap-test');
        self::assertNotNull($widget);

        return $widget;
    }
}
