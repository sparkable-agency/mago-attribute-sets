# Mago Attribute Sets

A tool for the [Mago](https://packagist.org/packages/mago-assistant/magento2-mago) admin assistant. It reads and rearranges a product attribute set. The attribute set controls the order of the fields on the product edit page.

| Action | What it does |
| --- | --- |
| `get_layout` | Lists the groups of an attribute set and the attributes in each group, in form order. Takes an attribute set ID or a SKU. |
| `arrange` | Arranges one group: sets the order of its attributes, moves attributes into it, creates the group, or sets its position. |

Example questions:

- "The order of the attributes on my product edit page is not logical. Improve it."
- "Put all demo attributes in a new group named Specifications, after the first group."

## Installation

```
composer config repositories.sparkable-mago-attribute-sets vcs https://github.com/sparkable-agency/mago-attribute-sets
composer require sparkable/magento-mago-attribute-sets
bin/magento module:enable Sparkable_MagoAttributeSets
bin/magento setup:upgrade
```

The `composer config` line adds the GitHub repository of this package.

## How a write works

- One `arrange` call changes one group. The admin user confirms each call on a card.
- The listed attributes come first in the group. The other attributes of the group follow in their current order.
- The card asks for an acknowledgement when a system attribute moves to another group.
- The tool does not add attributes to a set, remove them, or rename or delete groups.
- The tool keeps no copy of the old layout.

## Permissions and privacy

- The admin user needs `Magento_Catalog::sets`.
- All returned fields are catalog data and cross as they are.

## Tests

```
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/sparkable/magento-mago-attribute-sets/Test/Unit
```
