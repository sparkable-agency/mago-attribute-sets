<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Model;

use Magento\Catalog\Api\AttributeSetRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory as GroupCollectionFactory;

class AttributeSetLayout
{
    public function __construct(
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly GroupCollectionFactory $groupCollectionFactory,
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
    ) {
    }

    public function getSetName(int $attributeSetId): string
    {
        return (string)$this->attributeSetRepository->get($attributeSetId)->getAttributeSetName();
    }

    public function getGroups(int $attributeSetId): array
    {
        $attributes = $this->attributeCollectionFactory->create();
        $attributes->setAttributeSetFilter($attributeSetId);
        $attributes->addVisibleFilter();

        $attributesByGroup = [];
        foreach ($attributes as $attribute) {
            $attributesByGroup[(int)$attribute->getData('attribute_group_id')][] = [
                'attribute_code' => (string)$attribute->getAttributeCode(),
                'label' => (string)$attribute->getData('frontend_label'),
                'is_user_defined' => (bool)$attribute->getIsUserDefined(),
                'sort_order' => (int)$attribute->getData('sort_order'),
            ];
        }

        $groupCollection = $this->groupCollectionFactory->create();
        $groupCollection->setAttributeSetFilter($attributeSetId)->setSortOrder();

        $groups = [];
        foreach ($groupCollection as $group) {
            $groupAttributes = $attributesByGroup[(int)$group->getId()] ?? [];
            usort($groupAttributes, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
            $groups[] = [
                'group_id' => (int)$group->getId(),
                'name' => (string)$group->getAttributeGroupName(),
                'code' => (string)$group->getAttributeGroupCode(),
                'sort_order' => (int)$group->getSortOrder(),
                'attributes' => $groupAttributes,
            ];
        }

        return $groups;
    }
}
