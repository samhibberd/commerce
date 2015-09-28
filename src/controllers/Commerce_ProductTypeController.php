<?php
namespace Craft;

/**
 * Class Commerce_ProductTypeController
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   http://craftcommerce.com/license Craft Commerce License Agreement
 * @see       http://craftcommerce.com
 * @package   craft.plugins.commerce.controllers
 * @since     1.0
 */
class Commerce_ProductTypeController extends Commerce_BaseController
{
    protected $allowAnonymous = false;

    public function actionIndex ()
    {
        if(!craft()->userSession->getUser()->can('accessCommerce')){
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $productTypes = craft()->commerce_productType->getAll();
        $this->renderTemplate('commerce/settings/producttypes/index',
            compact('productTypes'));
    }

    public function actionEditProductType (array $variables = [])
    {
        if(!craft()->userSession->getUser()->can('accessCommerce')){
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $variables['brandNewProductType'] = false;

        if (empty($variables['productType']))
        {
            if (!empty($variables['productTypeId']))
            {
                $productTypeId = $variables['productTypeId'];
                $variables['productType'] = craft()->commerce_productType->getById($productTypeId);

                if (!$variables['productType'])
                {
                    throw new HttpException(404);
                }
            }
            else
            {
                $variables['productType'] = new Commerce_ProductTypeModel();
                $variables['brandNewProductType'] = true;
            };
        }

        if (!empty($variables['productTypeId']))
        {
            $variables['title'] = $variables['productType']->name;
        }
        else
        {
            $variables['title'] = Craft::t('Create a Product Type');
        }

        $this->renderTemplate('commerce/settings/producttypes/_edit', $variables);
    }

    public function actionSaveProductType ()
    {
        if(!craft()->userSession->getUser()->can('accessCommerce')){
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $this->requirePostRequest();

        $productType = new Commerce_ProductTypeModel();

        // Shared attributes
        $productType->id = craft()->request->getPost('productTypeId');
        $productType->name = craft()->request->getPost('name');
        $productType->handle = craft()->request->getPost('handle');
        $productType->hasDimensions = craft()->request->getPost('hasDimensions');
        $productType->hasUrls = craft()->request->getPost('hasUrls');
        $productType->hasVariants = craft()->request->getPost('hasVariants');
        $productType->template = craft()->request->getPost('template');
        $productType->titleFormat = craft()->request->getPost('titleFormat');

        $locales = [];

        foreach (craft()->i18n->getSiteLocaleIds() as $localeId)
        {
            $locales[$localeId] = new Commerce_ProductTypeLocaleModel([
                'locale'    => $localeId,
                'urlFormat' => craft()->request->getPost('urlFormat.'.$localeId)
            ]);
        }

        $productType->setLocales($locales);

        // Set the product type field layout
        $fieldLayout = craft()->fields->assembleLayoutFromPost();
        $fieldLayout->type = 'Commerce_Product';
        $productType->asa('productFieldLayout')->setFieldLayout($fieldLayout);

        // Set the variant field layout
        $variantFieldLayout = craft()->fields->assembleLayoutFromPost('variant-layout');
        $variantFieldLayout->type = 'Commerce_Variant';
        $productType->asa('variantFieldLayout')->setFieldLayout($variantFieldLayout);

        // Save it
        if (craft()->commerce_productType->save($productType))
        {
            craft()->userSession->setNotice(Craft::t('Product type saved.'));
            $this->redirectToPostedUrl($productType);
        }
        else
        {
            craft()->userSession->setError(Craft::t('Couldn’t save product type.'));
        }

        // Send the productType back to the template
        craft()->urlManager->setRouteVariables([
            'productType' => $productType
        ]);
    }


    public function actionDeleteProductType ()
    {
        if(!craft()->userSession->getUser()->can('accessCommerce')){
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $productTypeId = craft()->request->getRequiredPost('id');

        craft()->commerce_productType->deleteById($productTypeId);
        $this->returnJson(['success' => true]);
    }
} 