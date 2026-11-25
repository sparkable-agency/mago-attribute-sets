<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Test\Unit\Service\Tool\AttributeSets;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoAttributeSets\Model\AttributeSetLayout;
use Sparkable\MagoAttributeSets\Service\Tool\AttributeSets\GetLayoutAction;

class GetLayoutActionTest extends TestCase
{
    private InternalApiClientInterface&MockObject $apiClient;
    private GetLayoutAction $action;

    protected function setUp(): void
    {
        $layout = $this->createMock(AttributeSetLayout::class);
        $layout->method('getSetName')->with(4)->willReturn('Default');
        $layout->method('getGroups')->with(4)->willReturn([[
            'group_id' => 7,
            'name' => 'General',
            'code' => 'product-details',
            'sort_order' => 10,
            'attributes' => [
                ['attribute_code' => 'name', 'label' => 'Name', 'is_user_defined' => false, 'sort_order' => 10],
            ],
        ]]);
        $this->apiClient = $this->createMock(InternalApiClientInterface::class);
        $this->action = new GetLayoutAction($layout, $this->apiClient);
    }

    public function testReadsTheAttributeSetOfAProduct(): void
    {
        $this->apiClient->expects(self::once())
            ->method('get')
            ->with('products/GEAR+1', [], 7)
            ->willReturn(['sku' => 'GEAR 1', 'attribute_set_id' => 4]);

        self::assertSame(
            [
                'attribute_set_id' => 4,
                'attribute_set_name' => 'Default',
                'groups' => [[
                    'name' => 'General',
                    'attributes' => [['attribute_code' => 'name', 'label' => 'Name', 'is_user_defined' => false]],
                ]],
            ],
            $this->action->execute(['sku' => 'GEAR 1'], 7)
        );
    }

    public function testNeedsAnAttributeSetOrASku(): void
    {
        self::assertSame(['error' => 'Give attribute_set_id or sku'], $this->action->execute([], 7));
    }

    public function testReportsAnUnknownProduct(): void
    {
        $this->apiClient->method('get')->willReturn(['error' => 'Resource not found']);

        self::assertSame(['error' => 'Product not found: nope'], $this->action->execute(['sku' => 'nope'], 7));
    }
}
