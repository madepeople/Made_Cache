<?php

/**
 * Inject cache variables for catalog blocks
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Blocktype_Catalog
{
    /**
     * Product view block, don't cache if rendered with cart item update
     *
     * @param Mage_Catalog_Block_Product_View $block
     */
    public function applyProductView(Mage_Catalog_Block_Product_Abstract $block)
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

        // Set cache keys
        $keys = $block->getCacheKeys();

        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $keys = array_merge($keys, array(
            $customer->getGroupId(),
        ));

        $product = $block->getProduct();
        if ($product) {
            $tags = array(Mage_Catalog_Model_Product::CACHE_TAG . '_'
                . $block->getProduct()->getId());

            $taxCalculator = Mage::getModel('tax/calculation');
            $product = $block->getProduct();
            $keys = array_merge($keys, array(
                $product->getId(),
                $taxCalculator->getRate(
                    $taxCalculator->getRateRequest()
                        ->setProductClassId($product->getTaxClassId())
                )
            ));
        } else {
            $tags = array(Mage_Catalog_Model_Product::CACHE_TAG);
        }

        $block->setData('cache_tags', $tags);
        $block->setData('cache_keys', $keys);
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
        $toolbar = $block->getToolbarBlock();
        $productCollection = $block->getLoadedProductCollection();

        /**
         * @see Mage_Catalog_Block_Product_List_Toolbar::getCurrentOrder
         */
        if ($orders = $block->getAvailableOrders()) {
            $toolbar->setAvailableOrders($orders);
        }
        if ($sort = $block->getSortBy()) {
            $toolbar->setDefaultOrder($sort);
        }
        if ($dir = $block->getDefaultDirection()) {
            $toolbar->setDefaultDirection($dir);
        }
        if ($modes = $block->getModes()) {
            $toolbar->setModes($modes);
        }

        $toolbar->setCollection($productCollection);

        $productIds = array();
        foreach ($productCollection as $product) {
            $tags[] = Mage_Catalog_Model_Product::CACHE_TAG."_".$product->getId();
            $productIds[] = $product->getId();
        }

        if (!empty($productIds)) {
            $childIds = Mage::helper('cache')->getChildProductIds($productIds);
            foreach ($childIds as $childId) {
                $tags[] = Mage_Catalog_Model_Product::CACHE_TAG . '_' . $childId;
            }
        }

        $block->setData('cache_tags', $tags);

        // Set cache key
        $keys = $block->getCacheKeys();

        $taxRateRequest = Mage::getModel('tax/calculation')->getRateRequest();
        $categoryId = $this->_getCategoryIdForProductList($block);

        $keys = array_merge($keys, array(
            $categoryId,
            $toolbar->getCurrentOrder(),
            $toolbar->getCurrentDirection(),
            $toolbar->getCurrentMode(),
            $toolbar->getCurrentPage(),
            $toolbar->getLimit(),
            $taxRateRequest->getCountryId(),
            $taxRateRequest->getRegionId(),
            $taxRateRequest->getPostcode(),
            $taxRateRequest->getCustomerClassId(),
            Mage::registry('current_tag')
        ));
        $block->setData('cache_keys', $keys);
    }

    /**
     * Search result cache, caches the child product list block
     *
     * @param Mage_CatalogSearch_Block_Advanced_Result $block
     */
    public function applySearchResult(Mage_CatalogSearch_Block_Advanced_Result $block)
    {
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }

        // We cache the product list child, not the result block itself
        $block->setCacheLifetime(null);

        // The toolbar needs to apply sort order etc
        $productListBlock = $block->getChild('search_result_list');
        $this->applyProductList($productListBlock);
    }

    /**
     * Layered navigation block, clears on changed associated category ID
     *
     * @param Mage_Catalog_Block_Layer_View $block
     */
    public function applyCatalogLayerView(Mage_Catalog_Block_Layer_View $block)
    {
        // Set cache tags
        $tags = $block->getLayer()->getStateTags();
        $block->setData('cache_tags', $tags);

        $keys = $block->getCacheKeys();
        $keys[] = $block->getLayer()->getStateKey();

        foreach (Mage::app()->getRequest()->getParams() as $key => $value) {
            $value = Mage::helper('cache')->paramValueToCacheKey($value);
            $keys[] = $key . '_' . $value;
        }
        $block->setData('cache_keys', $keys);
    }
}