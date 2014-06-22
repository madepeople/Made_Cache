<?php
/**
 * Inject cache variables for checkout blocks
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer_Checkout
    extends Made_Cache_Model_Observer_Abstract
{
    /**
     * Return the quote item id
     * 
     * @param Mage_Checkout_Block_Cart_Sidebar $block
     * @return int
     */
    protected function _getQuoteId(Mage_Checkout_Block_Cart_Sidebar $block)
    {
        $quote = $block->getCustomQuote() ? $block->getCustomQuote() : $block->getQuote();
        return $quote->getId();
    }
    
    /**
     * Don't cache this block if there is no quote item set. We can't call
     * hasItems because it's too costly
     * 
     * @param Mage_Checkout_Block_Cart_Sidebar $block
     */
    public function applyCartSidebar(Mage_Checkout_Block_Cart_Sidebar $block)
    {
        if (!$block->getQuote()->getId()) {
            $block->setData('cache_lifetime', null);
            return;
        }
        
        // Set cache tags
        $tags = array('SALES_QUOTE_' . $this->_getQuoteId($block));
        $block->setData('cache_tags', $tags);
        
        // Set cache keys
        $keys = $this->_getBasicKeys($block);
        $keys[] = $this->_getQuoteId($block);
        
        $block->setData('cache_key', $this->_getCacheKey($keys, $block));
    }
}