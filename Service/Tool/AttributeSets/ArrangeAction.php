<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Service\Tool\AttributeSets;

use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use Sparkable\MagoAttributeSets\Model\AttributeSetLayout;

class ArrangeAction implements CautionedActionInterface, ValidatingActionInterface
{
    private const int SORT_STEP = 10;

    public function __construct(
        private readonly AttributeSetLayout $attributeSetLayout,
        private readonly InternalApiClientInterface $apiClient,
    ) {
    }

    public function getName(): string
    {
        return 'arrange';
    }

    public function getDescription(): string
    {
        return 'Arrange one group of a product attribute set: set the order of its attributes, move attributes '
            . 'into it from other groups, create the group, or set its position among the groups';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_set_id' => [
                'type' => 'integer',
                'description' => 'ID of the attribute set.',
            ],
            'group' => [
                'type' => 'string',
                'description' => 'Only for arrange: name of the group. A new name creates the group.',
            ],
            'attributes' => [
                'type' => 'string',
                'description' => 'Only for arrange: attribute codes of the set, comma-separated, in the wanted '
                    . 'order. They come first in the group. An attribute of another group moves to this group.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Only for arrange: place of the group among the groups, 1 is the first. Leave '
                    . 'out to keep the place.',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return array_fill_keys(
            ['success', 'attribute_set_id', 'attribute_set_name', 'group', 'group_created', 'changed_attribute_count'],
            [PiiClass::PUBLIC]
        );
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function findRefusal(array $params): ?array
    {
        $plan = $this->plan($params);

        return isset($plan['error']) ? $plan : null;
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $plan = $this->plan($params);
        if (isset($plan['error']) || !$plan['moved_system_attributes']) {
            return [];
        }

        return [sprintf(
            'These system attributes move to the group "%s": %s. Parts of the product edit page can depend on '
            . 'the group of a system attribute.',
            $plan['group'],
            implode(', ', $plan['moved_system_attributes'])
        )];
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }
        $plan = $this->plan($params);
        if (isset($plan['error'])) {
            return $plan;
        }

        $groupId = $plan['group_id'];
        foreach ($plan['group_saves'] as $group) {
            $saved = $this->saveGroup($plan['set_id'], $group, $adminUserId);
            if (isset($saved['error'])) {
                return ['error' => sprintf('Failed to save the group "%s": %s', $group['name'], $saved['error'])];
            }
            if ($group['group_id'] === null) {
                $groupId = (int)$saved['attribute_group_id'];
            }
        }

        $changedAttributeCount = 0;
        foreach ($plan['assign'] as $attributeCode => $sortOrder) {
            $assigned = $this->apiClient->post(
                'products/attribute-sets/attributes',
                [
                    'attributeSetId' => $plan['set_id'],
                    'attributeGroupId' => $groupId,
                    'attributeCode' => $attributeCode,
                    'sortOrder' => $sortOrder,
                ],
                $adminUserId
            );
            if (isset($assigned['error'])) {
                return ['error' => sprintf(
                    'Failed to place the attribute "%s": %s The tool placed %d attributes before the failure. '
                    . 'Read the layout again.',
                    $attributeCode,
                    $assigned['error'],
                    $changedAttributeCount
                )];
            }
            $changedAttributeCount++;
        }

        return [
            'success' => true,
            'attribute_set_id' => $plan['set_id'],
            'attribute_set_name' => $plan['set_name'],
            'group' => $plan['group'],
            'group_created' => $plan['group_id'] === null,
            'changed_attribute_count' => $changedAttributeCount,
        ];
    }

    private function saveGroup(int $attributeSetId, array $group, int $adminUserId): array
    {
        $payload = [
            'attribute_group_name' => $group['name'],
            'attribute_set_id' => $attributeSetId,
            'extension_attributes' => array_filter(
                ['sort_order' => $group['sort_order'], 'attribute_group_code' => $group['code']],
                static fn (mixed $value): bool => $value !== null
            ),
        ];
        if ($group['group_id'] === null) {
            return $this->apiClient->post('products/attribute-sets/groups', ['group' => $payload], $adminUserId);
        }

        return $this->apiClient->put(
            'products/attribute-sets/' . $attributeSetId . '/groups',
            ['group' => ['attribute_group_id' => $group['group_id']] + $payload],
            $adminUserId
        );
    }

    private function plan(array $params): array
    {
        $setId = (int)($params['attribute_set_id'] ?? 0);
        try {
            $setName = $this->attributeSetLayout->getSetName($setId);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        $name = is_scalar($params['group'] ?? null) ? trim((string)$params['group']) : '';
        if ($name === '') {
            return ['error' => 'Give the name of the group in "group"'];
        }

        $current = $this->attributeSetLayout->getGroups($setId);
        $target = ['group_id' => null, 'name' => $name, 'code' => null, 'sort_order' => null, 'attributes' => []];
        $others = [];
        $attributes = [];
        foreach ($current as $group) {
            if (mb_strtolower($group['name']) === mb_strtolower($name)) {
                $target = $group;
            } else {
                $others[] = $group;
            }
            foreach ($group['attributes'] as $attribute) {
                $attributes[$attribute['attribute_code']] = $attribute + ['group_id' => $group['group_id']];
            }
        }

        $codes = is_scalar($params['attributes'] ?? null)
            ? array_values(array_filter(array_map(trim(...), explode(',', (string)$params['attributes'])), strlen(...)))
            : [];
        foreach ($codes as $code) {
            if (!isset($attributes[$code])) {
                return ['error' => sprintf(
                    'Attribute "%s" is not in the attribute set "%s". Use the codes of get_layout.',
                    $code,
                    $setName
                )];
            }
        }
        if (count($codes) !== count(array_unique($codes))) {
            return ['error' => 'An attribute is listed twice'];
        }

        $assign = [];
        $movedSystemAttributes = [];
        $remaining = array_diff(array_column($target['attributes'], 'attribute_code'), $codes);
        foreach ([...$codes, ...$remaining] as $index => $code) {
            $sortOrder = ($index + 1) * self::SORT_STEP;
            $isMoved = $attributes[$code]['group_id'] !== $target['group_id'];
            if ($isMoved || $attributes[$code]['sort_order'] !== $sortOrder) {
                $assign[$code] = $sortOrder;
            }
            if ($isMoved && !$attributes[$code]['is_user_defined']) {
                $movedSystemAttributes[] = $code;
            }
        }

        $groupSaves = $this->planGroupSaves($target, $others, (int)($params['position'] ?? 0));
        if (!$groupSaves && !$assign) {
            return ['error' => sprintf('The attribute set "%s" already has this layout', $setName)];
        }

        return [
            'set_id' => $setId,
            'set_name' => $setName,
            'group_id' => $target['group_id'],
            'group' => $target['name'],
            'group_saves' => $groupSaves,
            'assign' => $assign,
            'moved_system_attributes' => $movedSystemAttributes,
        ];
    }

    private function planGroupSaves(array $target, array $others, int $position): array
    {
        if ($position < 1) {
            return $target['group_id'] === null ? [['sort_order' => null] + $target] : [];
        }

        array_splice($others, $position - 1, 0, [$target]);
        $saves = [];
        foreach ($others as $index => $group) {
            $sortOrder = ($index + 1) * self::SORT_STEP;
            if ($group['sort_order'] === $sortOrder) {
                continue;
            }
            $save = ['sort_order' => $sortOrder] + $group;
            $saves = $group['group_id'] === $target['group_id'] ? [$save, ...$saves] : [...$saves, $save];
        }

        return $saves;
    }
}
