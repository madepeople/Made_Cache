<?php

/**
 * Granular product list product cache
 *
 * @see Made_Cache_Block_Catalog_Product_List
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Block_Catalog_Product_List_Product
    extends Made_Cache_Block_Catalog_Product_List
{
    /**
     * Only clear on the product itself
     *
     * @return string
     */
    public function getCacheTags()
    {
        $tags = array(Mage_Catalog_Model_Product::CACHE_TAG . '_' .
            $this->getProduct()->getId());
        return $tags;
    }

    /**
     * Product unique - not category
     *
     * @return array
     */
    public function getCacheKeyInfo()
    {
        $keys = parent::getCacheKeyInfo();
        if (!is_array($keys)) {
            $keys = array();
        }

        $_taxCalculator = Mage::getModel('tax/calculation');
        $_customer = Mage::getSingleton('customer/session')->getCustomer();
        $_product = $this->getProduct();

        return array_merge($keys, array(
            $_product->getId(),
            Mage::app()->getStore()->getCode(),
            $_customer->getGroupId(),
            $_taxCalculator->getRate(
                $_taxCalculator->getRateRequest()
                    ->setProductClassId($_product->getTaxClassId())
            )
        ));
    }

    /**
     * No default values should be used
     *
     * @return string
     */
    public function getCacheKey()
    {
        $key = $this->getCacheKeyInfo();
        $key = array_values($key);  // Ignore array keys
        $key = implode('|', $key);
        $key = sha1($key);
        return $key;
    }

    /**
     * We can't cache the return URL, no idea where the cache came from and
     * it can't be user-dependent
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $additional
     */
    public function getAddToCartUrl($product, $additional = array())
    {
        $url = parent::getAddToCartUrl($product, $additional);
        $url = preg_replace('#/uenc/[^/]+/#', '/', $url);
        return $url;
    }

    /**
     * We don't need the extra collection/toolbar overhead created by default
     *
     * @return \Made_Cache_Block_Catalog_Product_List_Product
     */
    protected function _beforeToHtml()
    {
        return $this;
    }
}
