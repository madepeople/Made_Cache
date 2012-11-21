<?php
/**
 * Manage tag-specific cache cleaning
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_VarnishObserver
{
    /**
     * Simple, default is 0 expiry meaning no caching. For every action that
     * requires caching we add it explicitly. Static content is not within
     * this scope.
     * 
     * @param Varien_Event_Observer $observer
     */
    public function initializeResponseHeaders(Varien_Event_Observer $observer)
    {
        // Only manipulate headers if Varnish is in front
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        
        $response = $observer->getEvent()
                ->getControllerAction()
                ->getResponse();
        
        if (Mage::getStoreConfig('cache/varnish/debug')) {
            $response->setHeader('X-Made-Cache-Debug', 1);
        }
    }
    
    /**
     * Set TTL for varnish. Since we use ESI we only need to offer the
     * simple possibility to blacklist per request route.
     * 
     * @param Varien_Event_Observer $observer 
     */
    public function setVarnishCacheHeaders(Varien_Event_Observer $observer)
    {
        // Only manipulate headers if Varnish is in front or if there isn't
        // a messages block in the layout
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        
        $controller = $observer->getEvent()->getControllerAction();
        $ttl = Mage::helper('cache/varnish')->getRequestTtl($controller->getRequest());
        if (empty($ttl)) {
            return;
        }
        
        $response = $controller->getResponse();
        $response->setHeader('X-Made-Cache-Ttl', $ttl, true);
    }
        
    /**
     * Add 'varnish_enabled' to the list of layout handles, so developers
     * can target layout XML entries for when varnish is in front
     * 
     * @param Varien_Event_Observer $observer
     * @return void 
     */
    public function addLayoutHandle(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        
        $observer->getEvent()
                ->getLayout()
                ->getUpdate()
                ->addHandle('varnish_enabled');
    }
    
    /**
     * ESI tags for Varnish, needs the block to have the attribute esi === 1
     * as well as Varnish configured with for instance Phoenix_VarnishCache
     * 
     * @param Varien_Event_Observer $observer 
     */
    public function addEsiTag(Varien_Event_Observer $observer)
    {        
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        
        $block = $observer->getEvent()->getBlock();

        if ($block->getData('esi')) {
            $layoutHandles = $block->getLayout()->getUpdate()
                    ->getHandles();
            
            $esiPath = 'madecache/varnish/esi'
                    . '/hash/' . Mage::helper('cache/varnish')->getLayoutHash($block)
                    . '/block/' . base64_encode($block->getNameInLayout())
                    . '/layout/' . base64_encode(join(',', $layoutHandles))
            ;

            if (($product = Mage::registry('product')) !== null) {
                $esiPath .= '/misc/' . base64_encode(serialize(array(
                    'product' => $product->getId()
                )));
            }
            
            $html = Mage::helper('cache/varnish')->getEsiTag($esiPath);
            $transport = $observer->getEvent()->getTransport();
            $transport->setHtml($html);
        }
    }
    
    /**
     * Clear user-specific cache when at a non-cachable request because these
     * are what modify the session 
     * 
     * @param Varien_Event_Observer $observer
     */
    public function purgeUserCache(Varien_Event_Observer $observer)
    {
        // Only manipulate headers if Varnish is in front or if there isn't
        // a messages block in the layout
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        
        $controller = $observer->getEvent()->getControllerAction();
        $request = $controller->getRequest();
        $ttl = Mage::helper('cache/varnish')->getRequestTtl($request);
        if (!empty($ttl)) {
            // Only purge for routes that don't cache
            return;
        }
        
        if ($request->getModuleName() === 'madecache'
                && $request->getControllerName() === 'varnish') {
            // It's stupid for ESI requests to clear themselves
            return;
        }

        Mage::helper('cache/varnish')->purgeUserCache();
    }
    
    /**
     * Purge cache in Varnish including entity cache such as products,
     * categories and CMS pages by doing lookups in the rewrite table
     * 
     * Uses code from magneto-varnish.
     * 
     * @see https://github.com/madalinoprea/magneto-varnish/blob/master/code/Varnish/Model/Observer.php#L65
     * @param Varien_Event_Observer $observer 
     */
    public function purge(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }

        $tags = $observer->getTags();
        if (empty($tags)) {
            $errors = Mage::helper('cache/varnish')->flush();
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError("Varnish Purge failed");
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess("The Varnish cache storage has been flushed.");
            }
            return;
        }
        
        $urls = array();
        
        // Compute the urls for affected entities 
        foreach ((array)$tags as $tag) {
            $tag_fields = explode('_', $tag);
            if (count($tag_fields) === 3) {
                if ($tag_fields[1] == 'product') {
                    // Get urls for product
                    $product = Mage::getModel('catalog/product')->load($tag_fields[2]);
                    $urls = array_merge($urls, $this->_getUrlsForProduct($product));
                } elseif ($tag_fields[1] == 'category') {
                    $category = Mage::getModel('catalog/category')->load($tag_fields[2]);
                    $category_urls = $this->_getUrlsForCategory($category);
                    $urls = array_merge($urls, $category_urls);
                } elseif ($tag_fields[1] == 'page') {
                    $urls = $this->_getUrlsForCmsPage($tag_fields[2]);
                }
            }
        }

        // Transform urls to relative urls
        $relativeUrls = array();
        foreach ($urls as $url) {
            $relativeUrls[] = parse_url($url, PHP_URL_PATH);
        }
        
        if (!empty($relativeUrls)) {
            $relativeUrls = array_unique($relativeUrls);
            $errors = Mage::helper('cache/varnish')->purge($relativeUrls);
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError(
                    "Some Varnish purges failed: <br/>" . implode("<br/>", $errors));
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    "The following URLs have been cleared from Varnish: <br/>&nbsp;&nbsp;" . implode(", ", $relativeUrls));
            }
        }

        return $this;
    }
    
    /**
     * Returns all the urls related to product
     * 
     * Uses code from magneto-varnish.
     * 
     * @see https://github.com/madalinoprea/magneto-varnish/blob/master/code/Varnish/Model/Observer.php#L133
     * @param Mage_Catalog_Model_Product $product
     */
    protected function _getUrlsForProduct($product){
        $urls = array();

        $store_id = $product->getStoreId();

        $routePath = 'catalog/product/view';
        $routeParams['id']  = $product->getId();
        $routeParams['s']   = $product->getUrlKey();
        $routeParams['_store'] = (!$store_id ? 1: $store_id);
        $url = Mage::getUrl($routePath, $routeParams);
        $urls[] = $url;

        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        if (!Mage::getConfig('catalog/seo/product_use_categories')) {
            $rewrites->getSelect()
                    ->where("id_path = 'product/{$product->getId()}'");
        } else {
            // Also show full links with categories
            $rewrites->getSelect()
                    ->where("id_path = 'product/{$product->getId()}' OR id_path like 'product/{$product->getId()}/%'");
        }
        foreach ($rewrites as $r) {
            unset($routeParams);
            $routePath = '';
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $r->getStoreId();
            $url = Mage::getUrl($routePath, $routeParams);
            $urls[] = $url;
        }

        return $urls;
    }

    /** 
     * Returns all the urls pointing to the category
     * 
     * Uses code from magneto-varnish.
     * 
     * @see https://github.com/madalinoprea/magneto-varnish/blob/master/code/Varnish/Model/Observer.php#L171
     */
    protected function _getUrlsForCategory($category) {
        $urls = array();
        $routePath = 'catalog/category/view';

        $store_id = $category->getStoreId();
        $routeParams['id']  = $category->getId();
        $routeParams['s']   = $category->getUrlKey();
        $routeParams['_store'] = (!$store_id ? 1 : $store_id); # Default store id is 1
        $url = Mage::getUrl($routePath, $routeParams);
        $urls[] = $url;

        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        $rewrites->getSelect()->where("id_path = 'category/{$category->getId()}'");
        foreach ($rewrites as $r) {
            unset($routeParams);
            $routePath = '';
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $r->getStoreId();
            $routeParams['_nosid'] = True;
            $url = Mage::getUrl($routePath, $routeParams);
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Returns all urls related to this cms page
     * 
     * Uses code from magneto-varnish.
     * 
     * @see https://github.com/madalinoprea/magneto-varnish/blob/master/code/Varnish/Model/Observer.php#L201
     */
    protected function _getUrlsForCmsPage($cmsPageId)
    {
        $urls = array();
        $page = Mage::getModel('cms/page')->load($cmsPageId);
        if ($page->getId()) {
            $urls[] = '/' . $page->getIdentifier();
        }

        return $urls;
    }
}
