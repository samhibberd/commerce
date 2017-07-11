<?php

namespace craft\commerce\models;

use craft\behaviors\FieldLayoutBehavior;
use craft\commerce\base\Model;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;

/**
 * Product type model.
 *
 * @method null setFieldLayout(FieldLayout $fieldLayout)
 * @method FieldLayout getFieldLayout()
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.models
 * @since     1.0
 *
 * @property string                                                $cpEditUrl
 * @property string                                                $cpEditVariantUrl
 * @property ProductTypeSite[]                                     $siteSettings
 * @property mixed                                                 $variantFieldLayout
 * @property mixed                                                 $productFieldLayout
 *
 * @mixin FieldLayoutBehavior
 *
 */
class ProductType extends Model
{

    // Properties
    // =========================================================================

    /**
     * @var int ID
     */
    public $id;

    /**
     * @var string Name
     */
    public $name;

    /**
     * @var string Handle
     */
    public $handle;

    /**
     * @var bool Has URLs
     */
    public $hasUrls;

    /**
     * @var bool Has dimension
     */
    public $hasDimensions;

    /**
     * @var bool Has variants
     */
    public $hasVariants;

    /**
     * @var bool Has variant title field
     */
    public $hasVariantTitleField = true;

    /**
     * @var string Title format
     */
    public $titleFormat = '{product.title}';

    /**
     * @var string SKU format
     */
    public $skuFormat;

    /**
     * @var string Description format
     */
    public $descriptionFormat;

    /**
     * @var string Line item format
     */
    public $lineItemFormat;

    /**
     * @var string Template
     */
    public $template;

    /**
     * @var  int Field layout ID
     */
    public $fieldLayoutId;

    /**
     * @var int Variant layout ID
     */
    public $variantFieldLayoutId;

    /**
     * @var TaxCategory[]
     */
    private $_taxCategories;

    /**
     * @var ShippingCategory[]
     */
    private $_shippingCategories;

    /**
     * @var ProductTypeSite[]
     */
    private $_siteSettings;

    /**
     * @return null|string
     */
    public function __toString()
    {
        return $this->handle;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['id', 'fieldLayoutId', 'variantFieldLayoutId'], 'number', 'integerOnly' => true],
            [['name', 'handle', 'titleFormat'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
        ];
    }

    /**
     * @return string
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('commerce/settings/producttypes/'.$this->id);
    }

    /**
     * @return string
     */
    public function getCpEditVariantUrl(): string
    {
        return UrlHelper::cpUrl('commerce/settings/producttypes/'.$this->id.'/variant');
    }

    /**
     * Returns the product types's site-specific settings.
     *
     * @return ProductTypeSite[]
     */
    public function getSiteSettings(): array
    {
        if ($this->_siteSettings !== null) {
            return $this->_siteSettings;
        }

        if (!$this->id) {
            return [];
        }

        $this->setSiteSettings(ArrayHelper::index(Plugin::getInstance()->getProductTypes()->getProductTypeSites($this->id), 'siteId'));

        return $this->_siteSettings;
    }

    /**
     * Sets the product type's site-specific settings.
     *
     * @param ProductTypeSite[] $siteSettings
     *
     * @return void
     */
    public function setSiteSettings(array $siteSettings)
    {
        $this->_siteSettings = $siteSettings;

        foreach ($this->_siteSettings as $settings) {
            $settings->setProductType($this);
        }
    }

    /**
     *
     * @return ShippingCategory[]
     */
    public function getShippingCategories(): array
    {
        if (!$this->_shippingCategories) {
            $this->_shippingCategories = Plugin::getInstance()->getShippingCategories()->getShippingCategoriesByProductId($this->id);
        }

        return $this->_shippingCategories;
    }

    /**
     * @param int[]|ShippingCategory[] $shippingCategories
     */
    public function setShippingCategories($shippingCategories)
    {
        $categories = [];
        foreach ($shippingCategories as $category) {
            if (is_numeric($category)) {
                if ($category = Plugin::getInstance()->getShippingCategories()->getShippingCategoryById($category)) {
                    $categories[$category->id] = $category;
                }
            } else {
                if ($category instanceof ShippingCategory) {
                    if ($category = Plugin::getInstance()->getShippingCategories()->getShippingCategoryById($category)) {
                        $categories[$category->id] = $category;
                    }
                }
            }
        }

        $this->_shippingCategories = $categories;
    }

    /**
     * @return TaxCategory[]
     */
    public function getTaxCategories(): array
    {
        if (!$this->_taxCategories) {
            $this->_taxCategories = Plugin::getInstance()->getTaxCategories()->getTaxCategoriesByProductTypeId($this->id);
        }

        return $this->_taxCategories;
    }

    /**
     * @param int[]|TaxCategory[] $taxCategories
     */
    public function setTaxCategories($taxCategories)
    {
        $categories = [];
        foreach ($taxCategories as $category) {
            if (is_numeric($category)) {
                if ($category = Plugin::getInstance()->getTaxCategories()->getTaxCategoryById($category)) {
                    $categories[$category->id] = $category;
                }
            } else {
                if ($category instanceof TaxCategory) {
                    if ($category = Plugin::getInstance()->getTaxCategories()->getTaxCategoryById($category)) {
                        $categories[$category->id] = $category;
                    }
                }
            }
        }

        $this->_taxCategories = $categories;
    }


    /**
     * @return mixed
     */
    public function getProductFieldLayout()
    {
        return $this->getBehavior('productFieldLayout')->getFieldLayout();
    }

    /**
     * @return mixed
     */
    public function getVariantFieldLayout()
    {
        return $this->getBehavior('variantFieldLayout')->getFieldLayout();
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'productFieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Product::class,
                'idAttribute' => 'fieldLayoutId'
            ],
            'variantFieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Variant::class,
                'idAttribute' => 'variantFieldLayoutId'
            ],
        ];
    }
}