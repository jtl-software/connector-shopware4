<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Model
 */

namespace jtl\Connector\Shopware\Model;

use \jtl\Connector\Model\ProductCrossSelling as ProductCrossSellingModel;

/**
 * ProductCrossSelling Model
 * @access public
 */
class ProductCrossSelling extends ProductCrossSellingModel
{
    protected $fields = array(
        'id' => '',
        'crossProductId' => '',
        'crossSellingGroupId' => '',
        'productId' => ''
    );
    
    /**
     * (non-PHPdoc)
     * @see \jtl\Connector\Shopware\Model\DataModel::map()
     */
    public function map($toWawi = false, \stdClass $obj = null)
    {
        return DataModel::map($toWawi, $obj, $this);
    }
}
