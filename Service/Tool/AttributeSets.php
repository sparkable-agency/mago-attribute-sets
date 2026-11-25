<?php
declare(strict_types=1);

namespace Sparkable\MagoAttributeSets\Service\Tool;

use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class AttributeSets extends AbstractSkill implements PresentableToolInterface
{
    public function getName(): string
    {
        return 'sparkable_attribute_sets';
    }

    public function getDisplayName(): string
    {
        return 'Attribute sets';
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        return match ($action) {
            'get_layout' => 'Reading the attribute set...',
            'arrange' => 'Rearranging the attribute set...',
            default => null,
        };
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::sets';
    }

    protected function getBaseDescription(): string
    {
        return 'Read and rearrange a product attribute set: the groups and the attributes in them. The attribute '
            . 'set controls the order of the fields on the product edit page. Does not add attributes to a set, '
            . 'remove them, or rename or delete groups.';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
- The order of the fields on the product edit page comes from the attribute set of the product. Call "get_layout"
  with the SKU or the attribute set ID. Do not ask the user to open the attribute set page.
- Propose the new layout as a short table first. Call "arrange" after the user agrees.
- One "arrange" call changes one group. Use one call per group that changes. The listed attributes come first in
  the listed order. The other attributes of the group follow in their current order.
- To move an attribute to another group, list it in the call for the target group.
- Prefer to move user-defined attributes. Leave system attributes in their group unless the user asks: parts of
  the product form depend on the group of a system attribute.
- The tool keeps no copy of the old layout. Report the old layout from "get_layout" when the user asks.
TEXT;
    }
}
