<?php
/**
 * Manage tag-specific cache cleaning
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer
{

    protected $_shouldProfile = null;

    /**
     * Observer that injects cache values into specific blocks, we want
     * to do it like this instead of block rewrites to prevent other
     * third-party modules from breaking
     *
     * @param Varien_Event_Observer $observer
     */
    public function setupBlock(Varien_Event_Observer $observer)
    {
        $block = $observer->getEvent()->getBlock();
        if ($block->getCacheLifetime() === null) {
            // null lifetime means don't use cache
            return;
        }

        // Allow developers to manipulate block cache data
        Mage::dispatchEvent('made_cache_setup_block_before', array(
            'block' => $block
        ));

        // Set the temporary keys variable used by modifiers
        $keys = $block->getCacheKeyInfo();
        if (empty($keys)) {
            $keys = array();
        }
        $block->setCacheKeys($keys);

        $helper = Mage::helper('cache');

        $modifiers = $helper->getBlockModifiers($block);
        foreach ($modifiers as $modifier) {
            $modifierClasses = $helper->getModifiersByType($modifier);
            foreach ($modifierClasses as $modifierClass) {
                $object = Mage::getSingleton($modifierClass);
                $object->apply($block);
            }
        }

        $key = $helper->getBlockKey($block);
        $block->setData('cache_key', $key);

        Mage::dispatchEvent('made_cache_setup_block_after', array(
            'block' => $block
        ));
    }

    /**
     * Clear product cache after a review is saved
     *
     * @param Varien_Event_Observer $observer
     */
    public function reviewSaveAfter(Varien_Event_Observer $observer)
    {
        $object = $observer->getObject();
        $cacheKey = Mage_Catalog_Model_Product::CACHE_TAG . '_' . $object->getEntityPkValue();
        Mage::app()->cleanCache($cacheKey);
    }

    /**
     * CatalogRule invalidates cache on product save, and we really don't
     * want to clear everything which some blogposts suggest, so instead we
     * just mark the block cache as valid again. Stupid? You decide
     *
     * @param Varien_Event_Observer $observer
     */
    public function cleanCacheAfterProductSave(Varien_Event_Observer $observer)
    {
        $cacheInstance = Mage::app()->getCacheInstance();
        $types = $cacheInstance->load(Mage_Core_Model_Cache::INVALIDATED_TYPES);
        if ($types) {
            $types = unserialize($types);
            if (!empty($types[Mage_Core_Block_Abstract::CACHE_GROUP])) {
                unset($types[Mage_Core_Block_Abstract::CACHE_GROUP]);
                $cacheInstance->save(serialize($types), Mage_Core_Model_Cache::INVALIDATED_TYPES);
            }
        }
    }

    /**
     * Unset the uenc param for redirection of blocks that have cached links.
     * If we don't do this, links redirects to where user X came from when
     * the block was cached. This also means that cached return links for
     * ESI blocks return a visitor to the ESI URL, and we can't have that.
     *
     * @param Varien_Event_Observer $observer
     */
    public function removeUencParam(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getControllerAction()
                ->getRequest();

        // To this day, the only used encoding type is PARAM_NAME_URL_ENCODED
        $key = Mage_Core_Controller_Varien_Action::PARAM_NAME_URL_ENCODED;
        if (($param = $request->getParam($key)) !== null) {
            // Always remove redirections to Varnish actions
            $paramValue = base64_decode($param);
            if (strstr($paramValue, '/madecache/varnish/') ||
                    Mage::getStoreConfig('cache/general/remove_redirect_param')) {
                $request->setParam($key, null);
            }
        }
    }

    /**
     * Private getter to determine if the profiler should be enabled
     *
     * @return bool
     */
    protected function _getShouldProfile()
    {
        if (is_null($this->_shouldProfile)) {
            $this->_shouldProfile = (bool)Mage::getStoreConfig('cache/general/enable_profiler');
        }

        return $this->_shouldProfile;
    }

    /**
     * Start counting the time of rendering an uncached block
     *
     * @param Varien_Event_Observer $observer
     */
    public function profilerStart(Varien_Event_Observer $observer)
    {
        $shouldProfile = $this->_getShouldProfile();
        if ($shouldProfile === true) {
            $blockName = $observer->getEvent()
                    ->getBlock()
                    ->getNameInLayout();

            Made_Cache_Model_Profiler::start($blockName);
        }
    }

    /**
     * Stop counting the time of rendering an uncached block
     *
     * @param Varien_Event_Observer $observer
     */
    public function profilerEnd(Varien_Event_Observer $observer)
    {
        $shouldProfile = $this->_getShouldProfile();
        if ($shouldProfile === true) {
            $blockName = $observer->getEvent()
                    ->getBlock()
                    ->getNameInLayout();

            Made_Cache_Model_Profiler::end($blockName);
        }
    }

    /**
     * We have to clear the product cache on order save in case the stock qty
     * for the affected product(s) turned the product out of stock
     *
     * @param Varien_Event_Observer $observer
     */
    public function clearProductCacheAfterOrderPlace(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfigFlag('cache/general/clear_product_cache_placed_order')) {
            return;
        }

        $order = $observer->getEvent()->getOrder();

        $tags = array();
        foreach ($order->getAllItems() as $item) {
            $tags[] = Mage_Catalog_Model_Product::CACHE_TAG . '_' . $item->getProductId();
        }

        Mage::app()->cleanCache($tags);
    }
}
