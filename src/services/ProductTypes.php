<?php

namespace craft\commerce\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\events\ProductTypeEvent;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\models\ShippingCategory;
use craft\commerce\models\TaxCategory;
use craft\commerce\Plugin;
use craft\commerce\records\Product as ProductRecord;
use craft\commerce\records\ProductType as ProductTypeRecord;
use craft\commerce\records\ProductTypeSite as ProductTypeSiteRecord;
use craft\db\Query;
use craft\errors\ProductTypeNotFoundException;
use craft\events\SiteEvent;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use yii\base\Component;
use yii\base\Exception;

/**
 * Product type service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class ProductTypes extends Component
{

    /**
     * @event CategoryGroupEvent The event that is triggered before a category group is saved.
     */
    const EVENT_BEFORE_SAVE_PRODUCTTYPE = 'beforeSaveProductType';

    /**
     * @event ProductTypeEvent The event that is triggered after a product type is saved.
     */
    const EVENT_AFTER_SAVE_PRODUCTTYPE = 'afterSaveProductType';

    /**
     * @var bool
     */
    private $_fetchedAllProductTypes = false;

    /**
     * @var ProductType[]
     */
    private $_productTypesById;

    /**
     * @var ProductType[]
     */
    private $_productTypesByHandle;

    /**
     * @var
     */
    private $_allProductTypeIds;

    /**
     * @var
     */
    private $_editableProductTypeIds;

    /**
     * Returns all editable product types.
     *
     * @param string|null $indexBy
     *
     * @return ProductType[] All the editable product types.
     */
    public function getEditableProductTypes($indexBy = null): array
    {
        $editableProductTypeIds = $this->getEditableProductTypeIds();
        $editableProductTypes = [];

        foreach ($this->getAllProductTypes() as $productTypes) {
            if (in_array($productTypes->id, $editableProductTypeIds)) {
                if ($indexBy) {
                    $editableProductTypes[$productTypes->$indexBy] = $productTypes;
                } else {
                    $editableProductTypes[] = $productTypes;
                }
            }
        }

        return $editableProductTypes;
    }

    /**
     * Returns all of the product type IDs that are editable by the current user.
     *
     * @return array All the editable product types’ IDs.
     */
    public function getEditableProductTypeIds(): array
    {
        if (null === $this->_editableProductTypeIds) {
            $this->_editableProductTypeIds = [];
            $allProductTypeIds = $this->getAllProductTypeIds();

            foreach ($allProductTypeIds as $productTypeId) {
                if (Craft::$app->getUser()->checkPermission('commerce-manageProductType:'.$productTypeId)) {
                    $this->_editableProductTypeIds[] = $productTypeId;
                }
            }
        }

        return $this->_editableProductTypeIds;
    }

    /**
     * Returns all of the product type IDs.
     *
     * @return array All the product types’ IDs.
     */
    public function getAllProductTypeIds(): array
    {
        if (null === $this->_allProductTypeIds) {
            $this->_allProductTypeIds = [];
            $productTypes = $this->getAllProductTypes();

            foreach ($productTypes as $productType) {
                $this->_allProductTypeIds[] = $productType->id;
            }
        }

        return $this->_allProductTypeIds;
    }

    /**
     * Returns all Product Types
     *
     * @return ProductType[]
     */
    public function getAllProductTypes(): array
    {
        
        if (!$this->_fetchedAllProductTypes) {
            $results = $this->_createProductTypeQuery()->all();

            foreach ($results as $result) {
                $this->_memoizeProductType(new ProductType($result));
            }

            $this->_fetchedAllProductTypes = true;
        }

        return $this->_productTypesById;
    }

    /**
     * @param string $handle
     *
     * @return ProductType|null
     */
    public function getProductTypeByHandle($handle)
    {
        if (isset($this->_productTypesByHandle[$handle])) {
            return $this->_productTypesByHandle[$handle];
        }

        if ($this->_fetchedAllProductTypes) {
            return null;
        }

        $row = $this->_createProductTypeQuery()
            ->where(['handle' => $handle])
            ->one();

        if (!$row) {
            return null;
        }

        $this->_memoizeProductType(new ProductType($row));

        return $this->_productTypesByHandle[$handle];
    }

    /**
     * @param      $productTypeId
     *
     * @return array
     */
    public function getProductTypeSites($productTypeId): array
    {
        $rows = (new Query())
            ->select([
                'id',
                'productTypeId',
                'siteId',
                'uriFormat',
                'template'
            ])
            ->from('{{%commerce_producttypes_sites}}')
            ->where(['productTypeId' => $productTypeId])
            ->all();

        return ProductTypeSite::populateModels($rows);
    }

    /**
     * @param ProductType $productType
     * @param bool        $runValidation
     *
     * @return bool
     * @throws \Exception
     */
    public function saveProductType(ProductType $productType, bool $runValidation = true): bool
    {
        $titleFormatChanged = false;

        if ($runValidation && !$productType->validate()) {
            Craft::info('Product type not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNewProductType = !$productType->id;

        // Fire a 'beforeSaveProductType' event
        $this->trigger(self::EVENT_BEFORE_SAVE_PRODUCTTYPE, new ProductTypeEvent([
            'productType' => $productType,
            'isNew' => $isNewProductType,
        ]));

        if (!$isNewProductType) {
            $productTypeRecord = ProductTypeRecord::findOne($productType->id);

            if (!$productTypeRecord) {
                throw new ProductTypeNotFoundException("No product type exists with the ID '{$productType->id}'");
            }

            $oldProductTypeRow = $this->_createProductTypeQuery()->where(['id' => $productType->id])->one();
            $oldProductType = new ProductType($oldProductTypeRow);
        } else {
            $productTypeRecord = new ProductTypeRecord();
        }

        // If the product type does not have variants, default the title format.
        if (!$isNewProductType && !$productType->hasVariants) {
            $productType->hasVariantTitleField = false;
            $productType->titleFormat = '{product.title}';
        }

        $productTypeRecord->name = $productType->name;
        $productTypeRecord->handle = $productType->handle;

        $productTypeRecord->hasDimensions = $productType->hasDimensions;
        $productTypeRecord->hasVariants = $productType->hasVariants;
        $productTypeRecord->hasVariantTitleField = $productType->hasVariantTitleField;
        $productTypeRecord->titleFormat = $productType->titleFormat ?: '{product.title}';
        $productTypeRecord->skuFormat = $productType->skuFormat;
        $productTypeRecord->descriptionFormat = $productType->descriptionFormat;


        // Get the site settings
        $allSiteSettings = $productType->getSiteSettings();

        // Make sure they're all there
        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if (!isset($allSiteSettings[$siteId])) {
                throw new Exception('Tried to save a product type that is missing site settings');
            }
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {

            // Product Field Layout
            $fieldLayout = $productType->getProductFieldLayout();
            Craft::$app->getFields()->saveLayout($fieldLayout);
            $productType->fieldLayoutId = $fieldLayout->id;
            $productTypeRecord->fieldLayoutId = $fieldLayout->id;

            // Variant Field Layout
            $variantFieldLayout = $productType->getVariantFieldLayout();
            Craft::$app->getFields()->saveLayout($variantFieldLayout);
            $productType->variantFieldLayoutId = $variantFieldLayout->id;
            $productTypeRecord->variantFieldLayoutId = $variantFieldLayout->id;

            // Save the product type
            $productTypeRecord->save(false);

            // Now that we have a product type ID, save it on the model
            if (!$productType->id) {
                $productType->id = $productTypeRecord->id;
            }

            // Might as well update our cache of the product type while we have it.
            $this->_productTypesById[$productType->id] = $productType;


            if (!$isNewProductType && !$productType->hasVariantTitleField) {
                if ($productTypeRecord->titleFormat != $oldProductType->titleFormat) {
                    $titleFormatChanged = true;
                }
            }

            //Refresh all titles for variants of same product type if titleFormat changed.
            if ($productType->hasVariants && !$productType->hasVariantTitleField && $titleFormatChanged) {
                $criteria = Product::find();
                $criteria->typeId = $productType->id;
                $products = $criteria->all();
                foreach ($products as $product) {
                    foreach ($product->getVariants() as $variant) {
                        $title = Craft::$app->getView()->renderObjectTemplate($productType->titleFormat, $variant);
                        // updates to the same title in all sites
                        Craft::$app->getDb()->createCommand()->update('{{%content}}',
                            ['title' => $title],
                            ['elementId' => $variant->id]
                        );
                    }
                }
            }

            if (!$isNewProductType && $oldProductType->hasVariants && !$productType->hasVariants) {
                $criteria = Product::find();
                $criteria->typeId = $productType->id;
                $products = $criteria->all();
                /** @var Product $product */
                foreach ($products as $key => $product) {
                    if ($product && $product->getContent()->id) {
                        $defaultVariant = null;
                        // find out default variant
                        foreach ($product->getVariants() as $variant) {
                            if ($defaultVariant === null || $variant->isDefault) {
                                $defaultVariant = $variant;
                            }
                        }
                        // delete all non-default variants
                        foreach ($product->getVariants() as $variant) {
                            if ($defaultVariant !== $variant) {
                                Plugin::getInstance()->getVariants()->deleteVariantById($variant->id);
                            } else {
                                // The default variant must always be enabled.
                                $variant->enabled = true;
                                Plugin::getInstance()->getVariants()->saveVariant($variant);
                            }
                        }
                    }
                }
            }


            // Have any of the product type categories changed?
            if (!$isNewProductType) {
                // Get all previous categories
                $oldShippingCategories = $oldProductType->getShippingCategories();
                $oldTaxCategories = $oldProductType->getTaxCategories();
            }

            // Remove all existing categories
            Craft::$app->getDb()->createCommand()->delete('{{%commerce_producttypes_shippingcategories}}', 'productTypeId = :xid', [':xid' => $productType->id])->execute();
            Craft::$app->getDb()->createCommand()->delete('{{%commerce_producttypes_taxcategories}}', 'productTypeId = :xid', [':xid' => $productType->id])->execute();

            // Add back the new categories
            foreach ($productType->getShippingCategories() as $shippingCategory) {
                $data = ['productTypeId' => $productType->id, 'shippingCategoryId' => $shippingCategory->id];
                Craft::$app->getDb()->createCommand()->insert('{{%commerce_producttypes_shippingcategories}}', $data)->execute();
            }

            foreach ($productType->getTaxCategories() as $taxCategory) {
                $data = ['productTypeId' => $productType->id, 'taxCategoryId' => $taxCategory->id];
                Craft::$app->getDb()->createCommand()->insert('{{%commerce_producttypes_taxcategories}}', $data)->execute();
            }

            // Update all products that used the removed tax & shipping categories
            if (!$isNewProductType) {
                // Grab the new categories
                $newShippingCategories = $productType->getShippingCategories();
                $newTaxCategories = $productType->getTaxCategories();

                // Were any categories removed?
                $removedShippingCategoryIds = array_diff(array_keys($oldShippingCategories), array_keys($newShippingCategories));
                $removedTaxCategoryIds = array_diff(array_keys($oldTaxCategories), array_keys($newTaxCategories));

                // Update all products that used the removed product type shipping categories
                if ($removedShippingCategoryIds) {
                    $defaultShippingCategory = array_values($newShippingCategories)[0];
                    if ($defaultShippingCategory) {
                        $data = ['shippingCategoryId' => $defaultShippingCategory->id];
                        ProductRecord::updateAll($data, [
                            'shippingCategoryId' => $removedShippingCategoryIds,
                            'typeId' => $productType->id
                        ]);
                    }
                }

                // Update all products that used the removed product type tax categories
                if ($removedTaxCategoryIds) {
                    $defaultTaxCategory = array_values($newTaxCategories)[0];
                    if ($defaultTaxCategory) {
                        $data = ['taxCategoryId' => $defaultTaxCategory->id];
                        ProductRecord::updateAll($data, [
                            'taxCategoryId' => $removedTaxCategoryIds,
                            'typeId' => $productType->id
                        ]);
                    }
                }
            }


            // Update the site settings
            // -----------------------------------------------------------------

            $sitesNowWithoutUrls = [];
            $sitesWithNewUriFormats = [];

            if (!$isNewProductType) {
                // Get the old product type site settings
                $allOldSiteSettingsRecords = ProductTypeSiteRecord::find()
                    ->where(['productTypeId' => $productType->id])
                    ->indexBy('siteId')
                    ->all();
            }

            foreach ($allSiteSettings as $siteId => $siteSettings) {
                // Was this already selected?
                if (!$isNewProductType && isset($allOldSiteSettingsRecords[$siteId])) {
                    $siteSettingsRecord = $allOldSiteSettingsRecords[$siteId];
                } else {
                    $siteSettingsRecord = new ProductTypeSiteRecord();
                    $siteSettingsRecord->productTypeId = $productType->id;
                    $siteSettingsRecord->siteId = $siteId;
                }

                $siteSettingsRecord->hasUrls = $siteSettings->hasUrls;
                $siteSettingsRecord->uriFormat = $siteSettings->uriFormat;
                $siteSettingsRecord->template = $siteSettings->template;

                if (!$siteSettingsRecord->getIsNewRecord()) {
                    // Did it used to have URLs, but not anymore?
                    if ($siteSettingsRecord->isAttributeChanged('hasUrls', false) && !$siteSettings->hasUrls) {
                        $sitesNowWithoutUrls[] = $siteId;
                    }

                    // Does it have URLs, and has its URI format changed?
                    if ($siteSettings->hasUrls && $siteSettingsRecord->isAttributeChanged('uriFormat', false)) {
                        $sitesWithNewUriFormats[] = $siteId;
                    }
                }

                $siteSettingsRecord->save(false);

                // Set the ID on the model
                $siteSettings->id = $siteSettingsRecord->id;
            }

            if (!$isNewProductType) {
                // Drop any site settings that are no longer being used, as well as the associated product/element
                // site rows
                $siteIds = array_keys($allSiteSettings);

                /** @noinspection PhpUndefinedVariableInspection */
                foreach ($allOldSiteSettingsRecords as $siteId => $siteSettingsRecord) {
                    if (!in_array($siteId, $siteIds, false)) {
                        $siteSettingsRecord->delete();
                    }
                }
            }

            // Finally, deal with the existing products...
            // -----------------------------------------------------------------

            if (!$isNewProductType) {
                // Get all of the product IDs in this group
                $productTypeIds = Product::find()
                    ->typeId($productType->id)
                    ->status(null)
                    ->limit(null)
                    ->ids();

                // Are there any sites left?
                if (!empty($allSiteSettings)) {
                    // Drop the old product URIs for any site settings that don't have URLs
                    if (!empty($sitesNowWithoutUrls)) {
                        $db->createCommand()
                            ->update(
                                '{{%elements_sites}}',
                                ['uri' => null],
                                [
                                    'elementId' => $productTypeIds,
                                    'siteId' => $sitesNowWithoutUrls,
                                ])
                            ->execute();
                    } else if (!empty($sitesWithNewUriFormats)) {
                        foreach ($productTypeIds as $productTypeId) {
                            App::maxPowerCaptain();

                            // Loop through each of the changed sites and update all of the products’ slugs and
                            // URIs
                            foreach ($sitesWithNewUriFormats as $siteId) {
                                $product = Product::find()
                                    ->id($productTypeId)
                                    ->siteId($siteId)
                                    ->status(null)
                                    ->one();

                                if ($product) {
                                    Craft::$app->getElements()->updateElementSlugAndUri($product, false, false);
                                }
                            }
                        }
                    }
                }
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        // Fire an 'afterSaveGroup' event
        $this->trigger(self::EVENT_AFTER_SAVE_PRODUCTTYPE, new ProductTypeEvent([
            'productType' => $productType,
            'isNew' => $isNewProductType,
        ]));

        return true;
    }

    /**
     * Deleted a
     *
     * @param $id
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteProductTypeById($id): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $productType = $this->getProductTypeById($id);

            $criteria = Product::find();
            $criteria->typeId = $productType->id;
            $criteria->status = null;
            $criteria->limit = null;
            $products = $criteria->all();

            foreach ($products as $product) {
                Craft::$app->getElements()->deleteElement($product);
            }

            $fieldLayoutId = $productType->getProductFieldLayout()->id;
            Craft::$app->getFields()->deleteLayoutById($fieldLayoutId);
            if ($productType->hasVariants) {
                Craft::$app->getFields()->deleteLayoutById($productType->getVariantFieldLayout()->id);
            }

            $productTypeRecord = ProductTypeRecord::findOne($productType->id);
            $affectedRows = $productTypeRecord->delete();

            if ($affectedRows) {
                $transaction->commit();
            }

            return (bool)$affectedRows;
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * @param int $productTypeId
     *
     * @return ProductType|null
     */
    public function getProductTypeById($productTypeId)
    {

        if (isset($this->_productTypesById[$productTypeId])) {
            return $this->_productTypesById[$productTypeId];
        }

        if ($this->_fetchedAllProductTypes) {
            return null;
        }

        $row = $this->_createProductTypeQuery()
            ->where(['id' => $productTypeId])
            ->one();

        if (!$row) {
            return null;
        }

        $this->_memoizeProductType(new ProductType($row));

        return $this->_productTypesById[$productTypeId];
    }

    /**
     * Returns whether a product type’s products have URLs, and if the template path is valid.
     *
     * @param ProductType $productType
     *
     * @return bool
     */
    public function isProductTypeTemplateValid(ProductType $productType): bool
    {
        if ($productType->hasUrls) {
            // Set Craft to the site template mode
            $templatesService = Craft::$app->getView();
            $oldTemplateMode = $templatesService->getTemplateMode();
            $templatesService->setTemplateMode($templatesService::TEMPLATE_MODE_SITE);

            // Does the template exist?
            $templateExists = $templatesService->doesTemplateExist($productType->template);

            // Restore the original template mode
            $templatesService->setTemplateMode($oldTemplateMode);

            if ($templateExists) {
                return true;
            }
        }

        return false;
    }


    public function addSiteHandler(SiteEvent $event): bool
    {

        if ($event->isNew) {
            $allSiteSettings = (new Query())
                ->select(['productTypeId', 'uriFormat', 'template', 'hasUrls'])
                ->from(['{{%commerce_producttypes_sites}}'])
                ->where(['siteId' => Craft::$app->getSites()->getPrimarySite()->id])
                ->all();

            if (!empty($allSiteSettings)) {
                $newSiteSettings = [];

                foreach ($allSiteSettings as $siteSettings) {
                    $newSiteSettings[] = [
                        $siteSettings['productTypeId'],
                        $event->site->id,
                        $siteSettings['uriFormat'],
                        $siteSettings['template'],
                        $siteSettings['hasUrls']
                    ];
                }

                Craft::$app->getDb()->createCommand()
                    ->batchInsert(
                        '{{%commerce_producttypes_sites}}',
                        ['productTypeId', 'siteId', 'uriFormat', 'template', 'hasUrls'],
                        $newSiteSettings)
                    ->execute();
            }
        }

        return true;
    }

    // Private methods
    // =========================================================================

    /**
     * Memoize a product type
     *
     * @param ProductType $productType
     */
    private function _memoizeProductType(ProductType $productType)
    {
        $this->_productTypesById[$productType->id] = $productType;
        $this->_productTypesByHandle[$productType->handle] = $productType;
    }

    /**
     * Returns a Query object prepped for retrieving tax categories.
     *
     * @return Query
     */
    private function _createProductTypeQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'fieldLayoutId',
                'variantFieldLayoutId',
                'name',
                'handle',
                'hasDimensions',
                'hasVariants',
                'hasVariantTitleField',
                'titleFormat',
                'skuFormat',
                'descriptionFormat',
            ])
            ->from(['{{%commerce_producttypes}}']);
    }
}