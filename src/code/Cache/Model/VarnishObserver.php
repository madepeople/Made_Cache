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

    const FORM_KEY_PLACEHOLDER = '_FORM_KEY_PLACEHOLDER';

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
        $helper = Mage::helper('cache/varnish');
        if (!$helper->shouldUse()) {
            return;
        }

        $controller = $observer->getEvent()
            ->getFront()
            ->getAction();

        $response = $controller->getResponse();
        if (Mage::getStoreConfig('cache/varnish/debug')) {
            $response->setHeader('X-Made-Cache-Debug', 1);
        }

        $ttl = $helper->getRequestTtl($controller->getRequest());
        if (empty($ttl)) {
            return;
        }

        $tagHeaders = $helper->getTagHeaders($this->_blockCacheTags);
        foreach ($tagHeaders as $header => $value) {
            $response->setHeader($header, $value, true);
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
                $layout = base64_encode('default,varnish_enabled');
            }

            $esiPath = '/madecache/varnish/esi'
                . '/block/' . base64_encode($block->getNameInLayout())
                . '/hash/' . $hash
                . '/layout/' . $layout
                . '/misc/' . base64_encode(json_encode($misc));

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
        // Only manipulate headers if Varnish is in front or if there are no
        // messages in the session
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }

        // Don't clear the messages cache inside our own requests, it just
        // takes cpu and fills the ban list
        if (Mage::app()->getRequest()->getModuleName() === 'madecache') {
            return;
        }

        if (Mage::helper('cache')->responseHasMessages()) {
            Mage::helper('cache/varnish')
                ->purgeUserCache(Made_Cache_Helper_Varnish::USER_CACHE_TYPE_MESSAGES);
        }
    }

    /**
     * Purge all user (session) specific ESI cache
     *
     * @param Varien_Event_Observer $observer
     */
    public function purgeUserCache(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }

        Mage::helper('cache/varnish')
            ->purgeUserCache(Made_Cache_Helper_Varnish::USER_CACHE_TYPE_ESI);
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
        $helper = Mage::helper('cache/varnish');
        if (!$helper->shouldUse()) {
            return;
        }

        $tags = (array)$observer->getEvent()->getTags();
        $errors = $helper->banTags($tags);

        // Varnish purge messages should only appear in the backend
        if (!empty($errors)) {
            if (Mage::app()->getStore()->isAdmin()) {
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
        $helper = Mage::helper('cache/varnish');
        if (!$helper->shouldUse()) {
            return;
        }

        // Ignore the mass including block_html tag
        $ignoreTags = array(
            Mage_Core_Block_Abstract::CACHE_GROUP,
        );
        $block = $observer->getBlock();
        $request = $block->getRequest();
        if ($request->isPost()) {
            // We never cache POST requests in varnish regardless
            return;
        }
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
     * Sessions that don't exist can't be hi-jacked, so we simply pass them
     * through
     *
     * @param Varien_Event_Observer $observer
     * @see replaceFormKeyPlaceholder
     */
    public function anonymousPassthrough(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        if (!Mage::getStoreConfig('cache/varnish/csrf_replacement')) {
            return;
        }

        if (Mage::registry('esi_action')) {
            // Don't do this for ESI actions as they need the correct key
            return;
        }

        if (empty($_COOKIE['frontend'])) {
            // No frontend session yet, which means there is nothing to hi-jack
            // and we are allowed to pass the request through
            $formKey = 'pass-through';
            $session = Mage::getSingleton('core/session');
            $session->setData('_form_key', $formKey);
            $frontController = $observer->getEvent()->getControllerAction();
            $request = $frontController->getRequest();
            $request->setPost('form_key', $formKey);
            $request->setParam('form_key', $formKey);
        }
    }

    /**
     * Replaces the form key value with a placeholder soon to be replaced
     * by an ESI tag
     *
     * @param Varien_Event_Observer $observer
     * @see replaceFormKeyPlaceholder
     */
    public function replaceFormKey(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        if (!Mage::getStoreConfig('cache/varnish/csrf_replacement')) {
            return;
        }

        if (Mage::registry('esi_action')) {
            // Don't do this for ESI actions as they need the correct key
            return;
        }

        $session = Mage::getSingleton('core/session');

        // Generate a real form key first so it can be fetched by ESI requests
        $currentFormKey = $session->getData('_form_key');
        if ($currentFormKey === 'pass-through') {
            $session->unsetData('_form_key');
        }
        $realFormKey = $session->getFormKey();

        // Then just replace it
        $session->setData('_form_key', self::FORM_KEY_PLACEHOLDER);
        $session->setData('_real_form_key', $realFormKey);
    }

    /**
     * Resets the form key back to the real one instead of the place holder,
     * since all blocks should have been rendered and the session hasn't been
     * written yet
     *
     * @param Varien_Event_Observer $observer
     */
    public function resetFormKey(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfig('cache/varnish/csrf_replacement')) {
            return;
        }

        $session = Mage::getSingleton('core/session');
        $realFormKey = $session->getData('_real_form_key');
        if (!empty($realFormKey)) {
            $session->setData('_form_key', $realFormKey);
            $session->unsetData('_real_form_key');
        }
    }

    /**
     * Sets the form key placeholder to the ESI tag
     *
     * @param Varien_Event_Observer $observer
     * @see replaceFormKey
     */
    public function replaceFormKeyPlaceholder(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('cache/varnish')->shouldUse()) {
            return;
        }
        if (!Mage::getStoreConfig('cache/varnish/csrf_replacement')) {
            return;
        }

        if (Mage::registry('esi_action')) {
            // Don't do this for ESI actions as they need the correct key
            return;
        }

        $formKeyBlock = Mage::app()->getLayout()
            ->getBlock('varnish.form_key');

        if (!$formKeyBlock) {
            return;
        }

        $esiTag = $formKeyBlock->setEsi(1)
            ->toHtml();

        $frontController = $observer->getEvent()->getFront();
        $response = $frontController->getResponse();
        $body = $response->getBody();
        $body = str_replace(self::FORM_KEY_PLACEHOLDER, $esiTag, $body);
        $response->setBody($body);
    }
}
