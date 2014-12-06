<?php

/**
 * Manage tag-specific cache cleaning
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_VarnishObserver
{

    const URL_CACHE_KEY_PREFIX = 'varnish_url_cache_key';

    /**
     * Storage of all block cache tags cached on the current page
     *
     * @var array
     */
    protected $_blockCacheTags = array();

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

        $controller = $observer->getEvent()
            ->getFront()
            ->getAction();

        $response = $controller->getResponse();
        if (Mage::getStoreConfig('cache/varnish/debug')) {
            $response->setHeader('X-Made-Cache-Debug', 1);
        }

        $ttl = Mage::helper('cache/varnish')->getRequestTtl($controller->getRequest());
        if (empty($ttl)) {
            return;
        }

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
            if ($block->getEsiUnique()) {
                $hash = Mage::helper('cache/varnish')->getLayoutHash($block);

                $layoutHandles = $block->getLayout()->getUpdate()
                    ->getHandles();
                $layout = base64_encode(join(',', $layoutHandles));

                if (($product = Mage::registry('product')) !== null) {
                    $misc = array(
                        'product' => $product->getId()
                    );
                }
            } else {
                $hash = 1;
                $misc = array();
                $layout = base64_encode('default');
            }

            $esiPath = 'madecache/varnish/esi'
                . '/block/' . base64_encode($block->getNameInLayout())
                . '/hash/' . $hash
                . '/layout/' . $layout
                . '/misc/' . base64_encode(serialize($misc));

            $html = Mage::helper('cache/varnish')->getEsiTag($esiPath);
            $transport = $observer->getEvent()->getTransport();
            $transport->setHtml($html);
        }
    }

    /**
     * Purge the messages ESI cache if the response actually has messages in it
     *
     * @param Varien_Event_Observer $observer
     */
    public function purgeMessagesCache(Varien_Event_Observer $observer)
    {
        // Only manipulate headers if Varnish is in front or if there isn't
        // a messages block in the layout
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }

        if (Mage::helper('cache')->responseHasMessages()) {
            Mage::helper('cache/varnish')
                ->purgeUserCache(Made_Cache_Helper_Varnish::USER_CACHE_TYPE_MESSAGES);
        }
    }

    /**
     * Flush everything currently in the Varnish cache
     *
     * @param Varien_Event_Observer $observer
     */
    public function flush(Varien_Event_Observer $observer)
    {
        if (!Mage::app()->useCache('varnish')) {
            return;
        }

        $eventName = $observer->getEvent()->getName();
        if ($eventName === 'adminhtml_cache_refresh_type') {
            $flush = $observer->getEvent()->getType() === 'varnish';
        } else {
            $tags = $observer->getEvent()->getTags();
            $flush = empty($tags);
        }

        if ($flush) {
            $errors = Mage::helper('cache/varnish')->flush();
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError("Varnish Purge failed: " . join(', ', $errors));
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess("The Varnish cache storage has been flushed.");
            }
            return;
        }
    }

    /**
     * Purge cache in Varnish using the block cache tag -> URL mapping. We use
     * the block tags to keep track of things because all of that is already
     * in place. Kind of the same way EE FPC does it, but different.
     *
     * @param Varien_Event_Observer $observer
     */
    public function purge(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }

        $tags = (array)$observer->getEvent()->getTags();
        $allUrls = array();

        $cache = Mage::app()->getCache();
        foreach ($tags as $cacheTag) {
            $cacheKey = self::URL_CACHE_KEY_PREFIX . '_' . $cacheTag;
            $urls = $cache->load($cacheKey);
            if ($urls === false) {
                continue;
            }
            $urls = unserialize($urls);
            $allUrls = array_merge($allUrls, $urls);
        }

        if (empty($allUrls)) {
            return;
        }

        $errors = Mage::helper('cache/varnish')->ban($allUrls);

        // Varnish purge messages should only appear in the backend
        if (Mage::app()->getStore()->isAdmin()) {
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError(
                    "Some Varnish purges failed: <br/>" . implode("<br/>", $errors));
            }
        }

    }

    /**
     * Store the current URL in the hash of the different tags
     *
     * @param Varien_Event_Observer $observer
     */
    public function saveBlockTags(Varien_Event_Observer $observer)
    {
        // Ignore the mass including block_html tag
        $ignoreTags = array(
            Mage_Core_Block_Abstract::CACHE_GROUP,
        );
        $block = $observer->getBlock();
        if ($block->getEsi() === 1) {
            // ESI block tags shouldn't be part of the main requests
            return;
        }
        $cacheTags = $block->getCacheTags();
        foreach ($cacheTags as $key => $val) {
            if (in_array($val, $ignoreTags)) {
                unset($cacheTags[$key]);
            }
        }
        if (empty($cacheTags)) {
            // Nothing to add
            return;
        }
        $blockCacheTags = array_merge($this->_blockCacheTags, $cacheTags);
        $blockCacheTags = array_unique($blockCacheTags);
        $this->_blockCacheTags = $blockCacheTags;
    }

    /**
     * Store the URLs in redis with the tags
     *
     * @param Varien_Event_Observer $observer
     */
    public function storeUrlTags(Varien_Event_Observer $observer)
    {
        if (empty($this->_blockCacheTags)) {
            // No cached blocks on the current page
            return;
        }
        $cache = Mage::app()->getCache();
        foreach ($this->_blockCacheTags as $cacheTag) {
            $cacheKey = self::URL_CACHE_KEY_PREFIX . '_' . $cacheTag;
            $urls = $cache->load($cacheKey);
            if ($urls === false) {
                $urls = array();
            } else {
                $urls = unserialize($urls);
            }
            $urls[] = $_SERVER['REQUEST_URI'];
            $urls = array_unique($urls);
            $urls = serialize($urls);
            $cache->save($urls, $cacheKey, array('FPC_VARNISH'));
        }
    }

}
