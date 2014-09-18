<?php

/**
 * Inject cache variables for checkout blocks
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Blocktype_Checkout
{
    /**
     * Return the quote item id
     *
     * @param Mage_Checkout_Block_Cart_Sidebar $block
     * @return int
     */
    protected function _getQuote(Mage_Checkout_Block_Cart_Abstract $block)
    {
        $quote = $block->getCustomQuote() ? $block->getCustomQuote() : $block->getQuote();
        return $quote;
    }

    /**
     * Don't cache this block if there is no quote item set. We can't call
     * hasItems because it's too costly
     *
     * @param Mage_Checkout_Block_Cart_Sidebar $block
     */
    public function applyCartSidebar(Mage_Checkout_Block_Cart_Abstract $block)
    {
        if (!$this->_getQuote($block)->getId()) {
            $block->setData('cache_lifetime', null);
            return;
        }

        // Set cache tags
        $quote = $this->_getQuote($block);
        $tags = $quote->getCacheIdTags();
        $block->setData('cache_tags', $tags);

        // Set cache keys
        $keys = $block->getCacheKeys();
        $keys[] = $quote->getId();
        $block->setData('cache_keys', $keys);
    }
}