<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Test\Unit\Service\Tool\AttributeSets;

use Magento\Framework\Exception\NoSuchEntityException;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoAttributeSets\Model\AttributeSetLayout;
use Sparkable\MagoAttributeSets\Service\Tool\AttributeSets\ArrangeAction;

class ArrangeActionTest extends TestCase
{
    private const int ADMIN_USER_ID = 7;

    private InternalApiClientInterface&MockObject $apiClient;
    private ArrangeAction $action;
    private array $calls = [];

    protected function setUp(): void
    {
        $attribute = static fn (string $code, int $sortOrder, bool $isUserDefined = false): array => [
            'attribute_code' => $code,
            'label' => ucfirst($code),
            'is_user_defined' => $isUserDefined,
            'sort_order' => $sortOrder,
        ];
        $layout = $this->createMock(AttributeSetLayout::class);
        $layout->method('getSetName')->willReturnCallback(
            static fn (int $id): string => $id === 4 ? 'Default' : throw new NoSuchEntityException(__('No such set.'))
        );
        $layout->method('getGroups')->willReturn([
            [
                'group_id' => 7,
                'name' => 'General',
                'code' => 'product-details',
                'sort_order' => 10,
                'attributes' => [$attribute('name', 10), $attribute('sku', 20), $attribute('grip', 30, true)],
            ],
            [
                'group_id' => 8,
                'name' => 'Prices',
                'code' => 'advanced-pricing',
                'sort_order' => 20,
                'attributes' => [$attribute('price', 10), $attribute('cost', 20)],
            ],
        ]);

        $this->apiClient = $this->createMock(InternalApiClientInterface::class);
        $record = function (string $method): callable {
            return function (string $endpoint, array $body) use ($method): array {
                $this->calls[] = [$method, $endpoint, $body['group'] ?? array_values($body)];

                return isset($body['group']) ? ['attribute_group_id' => 31] : ['result' => '1'];
            };
        };
        $this->apiClient->method('post')->willReturnCallback($record('post'));
        $this->apiClient->method('put')->willReturnCallback($record('put'));
        $this->action = new ArrangeAction($layout, $this->apiClient);
    }

    public function testReordersOneGroupAndKeepsTheUnlistedAttributesBehind(): void
    {
        $params = ['attribute_set_id' => 4, 'group' => 'general', 'attributes' => 'grip'];

        self::assertNull($this->action->findRefusal($params));
        self::assertSame([], $this->action->getCautions($params, self::ADMIN_USER_ID));

        $result = $this->action->execute($params, self::ADMIN_USER_ID);

        self::assertSame('General', $result['group']);
        self::assertFalse($result['group_created']);
        self::assertSame(3, $result['changed_attribute_count']);
        self::assertSame(
            [
                ['post', 'products/attribute-sets/attributes', [4, 7, 'grip', 10]],
                ['post', 'products/attribute-sets/attributes', [4, 7, 'name', 20]],
                ['post', 'products/attribute-sets/attributes', [4, 7, 'sku', 30]],
            ],
            $this->calls
        );
    }

    public function testCreatesAGroupAtAPositionAndMovesAttributesIntoIt(): void
    {
        $params = ['attribute_set_id' => 4, 'group' => 'Specs', 'attributes' => 'grip, cost', 'position' => 1];

        self::assertSame(
            [
                'These system attributes move to the group "Specs": cost. Parts of the product edit page can '
                . 'depend on the group of a system attribute.',
            ],
            $this->action->getCautions($params, self::ADMIN_USER_ID)
        );

        $result = $this->action->execute($params, self::ADMIN_USER_ID);

        self::assertTrue($result['group_created']);
        self::assertSame(2, $result['changed_attribute_count']);
        $group = static fn (string $name, int $sortOrder, ?string $code): array => [
            'attribute_group_name' => $name,
            'attribute_set_id' => 4,
            'extension_attributes' => ['sort_order' => $sortOrder] + ($code ? ['attribute_group_code' => $code] : []),
        ];
        self::assertSame(
            [
                ['post', 'products/attribute-sets/groups', $group('Specs', 10, null)],
                [
                    'put',
                    'products/attribute-sets/4/groups',
                    ['attribute_group_id' => 7] + $group('General', 20, 'product-details'),
                ],
                [
                    'put',
                    'products/attribute-sets/4/groups',
                    ['attribute_group_id' => 8] + $group('Prices', 30, 'advanced-pricing'),
                ],
                ['post', 'products/attribute-sets/attributes', [4, 31, 'grip', 10]],
                ['post', 'products/attribute-sets/attributes', [4, 31, 'cost', 20]],
            ],
            $this->calls
        );
    }

    public function testMovesAnExistingGroupWithoutTouchingItsAttributes(): void
    {
        $this->action->execute(['attribute_set_id' => 4, 'group' => 'Prices', 'position' => 1], self::ADMIN_USER_ID);

        self::assertSame(['put', 'put'], array_column($this->calls, 0));
        self::assertSame(8, $this->calls[0][2]['attribute_group_id']);
        self::assertSame(10, $this->calls[0][2]['extension_attributes']['sort_order']);
        self::assertSame(7, $this->calls[1][2]['attribute_group_id']);
        self::assertSame(20, $this->calls[1][2]['extension_attributes']['sort_order']);
    }

    public function testStopsAtTheFirstFailedRoute(): void
    {
        $apiClient = $this->createMock(InternalApiClientInterface::class);
        $apiClient->expects(self::exactly(2))->method('post')->willReturnOnConsecutiveCalls(
            ['result' => '1'],
            ['error' => 'No access.']
        );
        $layout = $this->createMock(AttributeSetLayout::class);
        $layout->method('getSetName')->willReturn('Default');
        $layout->method('getGroups')->willReturn([[
            'group_id' => 7,
            'name' => 'General',
            'code' => 'general',
            'sort_order' => 10,
            'attributes' => [
                ['attribute_code' => 'a', 'label' => 'A', 'is_user_defined' => true, 'sort_order' => 10],
                ['attribute_code' => 'b', 'label' => 'B', 'is_user_defined' => true, 'sort_order' => 20],
                ['attribute_code' => 'c', 'label' => 'C', 'is_user_defined' => true, 'sort_order' => 30],
            ],
        ]]);

        $result = (new ArrangeAction($layout, $apiClient))->execute(
            ['attribute_set_id' => 4, 'group' => 'General', 'attributes' => 'c,b,a'],
            self::ADMIN_USER_ID
        );

        self::assertSame(
            'Failed to place the attribute "a": No access. The tool placed 1 attributes before the failure. '
            . 'Read the layout again.',
            $result['error']
        );
    }

    #[DataProvider('refusedParamsProvider')]
    public function testRefusesBeforeTheConfirmation(array $params, string $expectedError): void
    {
        $this->apiClient->expects(self::never())->method('post');

        self::assertStringContainsString($expectedError, $this->action->findRefusal($params)['error']);
        self::assertSame([], $this->action->getCautions($params, self::ADMIN_USER_ID));
        self::assertArrayHasKey('error', $this->action->execute($params, self::ADMIN_USER_ID));
    }

    public static function refusedParamsProvider(): array
    {
        $set = static fn (array $params): array => ['attribute_set_id' => 4] + $params;

        return [
            'unknown set' => [['attribute_set_id' => 9, 'group' => 'General'], 'No such set.'],
            'no group' => [$set(['attributes' => 'name']), 'name of the group'],
            'attribute outside the set' => [
                $set(['group' => 'General', 'attributes' => 'name, color']),
                'Attribute "color" is not in the attribute set "Default"',
            ],
            'attribute twice' => [$set(['group' => 'General', 'attributes' => 'name,name']), 'listed twice'],
            'nothing changes' => [$set(['group' => 'General', 'attributes' => 'name, sku']), 'already has this layout'],
            'same position' => [$set(['group' => 'Prices', 'position' => 2]), 'already has this layout'],
        ];
    }
}
