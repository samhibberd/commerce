<?php

namespace craft\commerce\models;

use craft\commerce\base\Model;
use craft\commerce\Plugin;
use craft\helpers\UrlHelper;

/**
 * Currency model.
 *
 * @property int    $id
 * @property string $iso
 * @property bool   $primary
 * @property float  $rate
 * @property string $alphabeticCode
 * @property string $currency
 * @property string $entity
 * @property int    $minorUnit
 * @property int    $numericCode
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2017, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.commerce
 * @since     2.0
 */
class PaymentCurrency extends Model
{
    // Properties
    // =========================================================================

    /**
     * @var int ID
     */
    public $id;

    /**
     * @var string ISO code
     */
    public $iso;

    /**
     * @var bool Is primary currency
     */
    public $primary;

    /**
     * @var float Exchange rate vs primary currency
     */
    public $rate;

    /**
     * @var string Currency alphabetic code
     */
    public $alphabeticCode;

    /**
     * @var string Currency code
     */
    public $currency;

    /**
     * @var string Entity
     */
    public $entity;

    /**
     * @var int Number of digits after the decimal separator
     */
    public $minorUnit;

    /**
     * @var int Currency's ISO numeric code
     */
    public $numericCode;

    /**
     * @var Currency
     */
    private $_currency;

    /**
     * @return string
     */
    public function getCpEditUrl()
    {
        $val = UrlHelper::cpUrl('commerce/settings/paymentcurrencies/'.$this->id);
        return $val;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->iso;
    }

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();
        $fields['minorUnits'] = function($model) {
            return $model->getMinorUnits();
        };
        $fields['alphabeticCode'] = function($model) {
            return $model->getAlphabeticCode();
        };
        $fields['currency'] = function($model) {
            return $model->getCurrency();
        };
        $fields['numericCode'] = function($model) {
            return $model->getNumericCode();
        };
        $fields['entity'] = function($model) {
            return $model->getEntity();
        };

        return $fields;
    }

    /**
     * @return string|null
     */
    public function getAlphabeticCode()
    {
        if (null !== $this->_currency) {
            return $this->_currency->alphabeticCode;
        }
    }

    /**
     * @return int|null
     */
    public function getNumericCode()
    {
        if (null !== $this->_currency) {
            return $this->_currency->numericCode;
        }
    }

    /**
     * @return string|null
     */
    public function getEntity()
    {
        if (null !== $this->_currency) {
            return $this->_currency->entity;
        }
    }

    /**
     * @return int|null
     */
    public function getMinorUnit()
    {
        if (null !== $this->_currency) {
            return $this->_currency->minorUnit;
        }
    }

    /**
     * Alias of getCurrency()
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->getCurrency();
    }

    /**
     * @return string|null
     */
    public function getCurrency()
    {
        if (null !== $this->_currency) {
            return $this->_currency->currency;
        }
    }

    /**
     * Sets the Currency Model data on the Payment Currency
     *
     * @param $currency
     */
    public function setCurrency(Currency $currency)
    {
        $this->_currency = $currency;
    }
}