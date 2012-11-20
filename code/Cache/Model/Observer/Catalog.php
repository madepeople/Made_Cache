<?php
/**
 * Inject cache variables for catalog blocks
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer_Catalog
    extends Made_Cache_Model_Observer_Abstract
{
    /**
     * Product view block, don't cache if rendered with cart item update
     * 
     * @param Mage_Catalog_Block_Product_View $block
     */
    public function applyProductView(Mage_Catalog_Block_Product_View $block)
    {        
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }
        
        // Cart stuff is session-dependent
        $request = $block->getRequest();
        if ($request->getModuleName() == 'checkout'
                && $request->getControllerName() == 'cart'
                && $request->getActionName() == 'configure'
                && $request->getParam('id')) {
            $block->setData('cache_lifetime', null);
            return;
        }

        // Set cache tags
        $tags = array(Mage_Catalog_Model_Product::CACHE_TAG . '_'
            . $block->getProduct()->getId());
        $block->setData('cache_tags', $tags);
        
        // Set cache keys
        $keys = $this->_getBasicKeys($block);

        $_taxCalculator = Mage::getModel('tax/calculation');
        $_customer = Mage::getSingleton('customer/session')->getCustomer();
        $_product = $block->getProduct();

        $keys = array_merge($keys, array(
            $_product->getId(),
            $_customer->getGroupId(),
            $_taxCalculator->getRate(
                    $_taxCalculator->getRateRequest()
                            ->setProductClassId($_product->getTaxClassId())
            )
        ));
        $block->setData('cache_key', $this->_getCacheKey($keys));
    }
    
    /**
     * Depending on block usage, the category id resides in different places
     * 
     * @return int 
     */
    protected function _getCategoryIdForProductList($block)
    {
        return $block->getCategoryId() ?
                $block->getCategoryId() :
                Mage::getSingleton('catalog/layer')
                    ->getCurrentCategory()
                    ->getId()
        ;
    }
    
    /**
     * Make sure that the category view doesn't cache when there are
     * messages in the session
     * 
     * @param Mage_Catalog_Block_Category_View $block
     * @return type 
     */
    public function applyCategoryView(Mage_Catalog_Block_Category_View $block)
    {
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }
    }
    
    /**
     * Product list cache, needs to clear on displayed products
     * 
     * @param Mage_Catalog_Block_Product_List $block 
     */
    public function applyProductList(Mage_Catalog_Block_Product_List $block)
    {
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }
        
        // Set cache tags
        $tags = array();
        $tags[] = Mage_Catalog_Model_Category::CACHE_TAG . '_' . 
            $this->_getCategoryIdForProductList($block);
        
        // The toolbar needs to apply sort order etc
        $productCollection = $block->getLoadedProductCollection();
        $_toolbar = $block->getToolbarBlock();
        $_toolbar->setCollection($productCollection);
        
        foreach ($productCollection as $_product) {
            $tags[] = Mage_Catalog_Model_Product::CACHE_TAG."_".$_product->getId();
        }
        $block->setData('cache_tags', $tags);
        
        // Set cache key
        $keys = $this->_getBasicKeys($block);
        
        $_taxRateRequest = Mage::getModel('tax/calculation')->getRateRequest();
        $_customer = Mage::getSingleton('customer/session')->getCustomer();
        $_categoryId = $this->_getCategoryIdForProductList($block);
        
        foreach (Mage::app()->getRequest()->getParams() as $key => $value) {
            $value = Mage::helper('cache')->paramValueToCacheKey($value);
            $keys[] = $key . '_' . $value;
        }
        
        $keys = array_merge($keys, array(
            $_categoryId,
            $_toolbar->getCurrentOrder(),
            $_toolbar->getCurrentDirection(),
            $_toolbar->getCurrentMode(),
            $_toolbar->getCurrentPage(),
            $_toolbar->getLimit(),
            $_customer->getGroupId(),
            $_taxRateRequest->getCountryId(),
            $_taxRateRequest->getRegionId(),
            $_taxRateRequest->getPostcode(),
            $_taxRateRequest->getCustomerClassId(),
            Mage::registry('current_tag')
        ));
        $block->setData('cache_key', $this->_getCacheKey($keys));
    }
}