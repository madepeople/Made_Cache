<?php

/**
 * Controller that handles Varnish ESI actions and messages
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_VarnishController extends Mage_Core_Controller_Front_Action
{
    /**
     * Print specified block for its layout handle without the ESI tag
     */
    public function esiAction()
    {
        if (!$this->getRequest()->getHeader('Cookie')) {
            // Skip writing the session (when using the Redis session backend)
            // is there is no cookie header present. We still need to render
            // the default blocks
            Mage::register('skip_session_write', 1);
        }

        $layoutHandles = explode(',', base64_decode($this->getRequest()->getParam('layout')));
        $blockName = base64_decode($this->getRequest()->getParam('block'));
        $misc = unserialize(base64_decode($this->getRequest()->getParam('misc')));

        if (is_array($misc)) {
            if (isset($misc['product'])) {
                $product = Mage::getModel('catalog/product')->load($misc['product']);
                Mage::register('product', $product);
            }
        }

        $layout = $this->getLayout();
        $update = $layout->getUpdate();
        $update->load($layoutHandles);

        $layout->generateXml();
        $blockNodes = $layout->getNode()
            ->xpath('//*[@name="'.$blockName.'"]');

        if (!empty($blockNodes)) {
            foreach ($blockNodes as $node) {
                $layout->generateBlocks($node, true);
            }

            $block = $layout->getBlock($blockName)
                ->setEsi(0);
            $modifiers = Mage::helper('cache')->getBlockModifiers($block);
            $block->setCacheModifiers($modifiers);
            $this->getResponse()->setBody($block->toHtml());
        }
    }

    /**
     * Render all messages from currently used session namespaces
     *
     * @return void
     */
    public function messagesAction()
    {
        if (empty($_SESSION)) {
            return;
        }

        $messagesBlock = $this->getLayout()
            ->createBlock('core/messages', 'messages')
            ->setBypassVarnish(true);

        foreach ($_SESSION as $contents) {
            if (isset($contents['messages']) &&
                    $contents['messages'] instanceof Mage_Core_Model_Message_Collection) {
                if (!$contents['messages']->count()) {
                    continue;
                }
                $messagesBlock->addMessages($contents['messages']);
                $contents['messages']->clear();
            }
        }

        if (!$messagesBlock->getMessageCollection()->count()) {
            return;
        }

        $this->getResponse()
            ->setBody($messagesBlock->toHtml());
    }

    /**
     * Simply make sure that the session is valid
     *
     * @see Made_Cache_Block_Footer
     */
    public function cookieAction()
    {
        // IE needs this, otherwise it won't accept cookies from an AJAX request
        $this->getResponse()
            ->setHeader('P3P', 'CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"', true);
    }
}
