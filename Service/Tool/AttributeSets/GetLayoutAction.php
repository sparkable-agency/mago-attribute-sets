<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Service\Tool\AttributeSets;

use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use Sparkable\MagoAttributeSets\Model\AttributeSetLayout;

class GetLayoutAction implements ActionInterface
{
    public function __construct(
        private readonly AttributeSetLayout $attributeSetLayout,
        private readonly InternalApiClientInterface $apiClient,
    ) {
    }

    public function getName(): string
    {
        return 'get_layout';
    }

    public function getDescription(): string
    {
        return 'List the groups of a product attribute set and the attributes in each group, in the order of the '
            . 'product edit page';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_set_id' => [
                'type' => 'integer',
                'description' => 'ID of the attribute set.',
            ],
            'sku' => [
                'type' => 'string',
                'description' => 'Only for get_layout, in place of attribute_set_id: SKU of a product with the '
                    . 'attribute set.',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return array_fill_keys(
            [
                'attribute_set_id',
                'attribute_set_name',
                'name',
                'attribute_code',
                'label',
                'is_user_defined',
            ],
            [PiiClass::PUBLIC]
        );
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $attributeSetId = (int)($params['attribute_set_id'] ?? 0);
        $sku = is_scalar($params['sku'] ?? null) ? trim((string)$params['sku']) : '';
        if (!$attributeSetId && $sku !== '') {
            $product = $this->apiClient->get('products/' . urlencode($sku), [], $adminUserId);
            if (isset($product['error'])) {
                return ['error' => 'Product not found: ' . $sku];
            }
            $attributeSetId = (int)($product['attribute_set_id'] ?? 0);
        }
        if (!$attributeSetId) {
            return ['error' => 'Give attribute_set_id or sku'];
        }

        try {
            $name = $this->attributeSetLayout->getSetName($attributeSetId);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'attribute_set_id' => $attributeSetId,
            'attribute_set_name' => $name,
            'groups' => array_map(
                static fn (array $group): array => [
                    'name' => $group['name'],
                    'attributes' => array_map(
                        static fn (array $attribute): array => array_diff_key($attribute, ['sort_order' => 0]),
                        $group['attributes']
                    ),
                ],
                $this->attributeSetLayout->getGroups($attributeSetId)
            ),
        ];
    }
}
