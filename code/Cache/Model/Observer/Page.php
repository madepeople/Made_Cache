<?php
/**
 * Inject cache variables for page blocks
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer_Page
    extends Made_Cache_Model_Observer_Abstract
{
    /**
     * Make sure that catalog breadcrumbs are individually cached for
     * products / lists etc.
     */
    public function applyBreadcrumbs(Mage_Page_Block_Html_Breadcrumbs $block)
    {
        $keys = $this->_getBasicKeys($block);

        // category id could be taken from several places, if it's included at all.
        $categoryId = null;

        $tags = array();

        // get product and/or category id from request
        if ($this->_getRequest()->getRouteName() == 'catalog' && $this->_getRequest()->getControllerName() == 'category' && $this->_getRequest()->getActionName() == 'view') {
            $categoryId = $this->_getRequest()->getParam('id');
        } else if ($this->_getRequest()->getRouteName() == 'catalog' && $this->_getRequest()->getControllerName() == 'product' && $this->_getRequest()->getActionName() == 'view') {
            $tags[] = Mage_Catalog_Model_Product::CACHE_TAG . '_' . $this->_getRequest()->getParam('id');
            $keys[] = 'product_' . $this->_getRequest()->getParam('id');

            $categoryId = $this->_getRequest()->getParam('category');
        } else if ($this->_getRequest()->getRouteName() == 'cms' && $this->_getRequest()->getControllerName() == 'page' && $this->_getRequest()->getActionName() == 'view') {
            $pageId = $this->_getRequest()->getParam('page_id', $this->_getRequest()->getParam('id', false));

            if ($pageId) {
                $tags[] = Mage_Cms_Model_Page::CACHE_TAG . '_' . $pageId;
                $keys[] = 'cms_page_' . $pageId;
            }
        }

        // attempt to get category id from layer instead
        if (is_null($categoryId)) {
            $categoryId = Mage::getSingleton('catalog/layer')
                ->getCurrentCategory()
                ->getId();

            $keys[] = 'category_' . $categoryId;
        }

        if (!is_null($categoryId)) {
            $tags[] = Mage_Catalog_Model_Category::CACHE_TAG . '_' . $categoryId;
        }

        // assign cache tags
        $block->setData('cache_tags', $tags);

        $keys[] = 'route_' . strtolower($this->_getRequest()->getRouteName());
        $keys[] = 'controller_' . strtolower($this->_getRequest()->getControllerName());
        $keys[] = 'action_' . strtolower($this->_getRequest()->getActionName());

        $testKey = $this->_getCacheKey($keys, $block);
        $testData = Mage::app()->loadCache($testKey);

        Mage::log(strlen($testData), null, 'testa.log');
        Mage::log($keys, null, 'testa.log');

        $block->setData('cache_key', $this->_getCacheKey($keys, $block));
    }

    protected function _getRequest()
    {
        return Mage::app()->getRequest();
    }
}
