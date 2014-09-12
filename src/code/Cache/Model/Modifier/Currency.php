<?php

/**
 * Inject cache variables depending on the currency
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Currency
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeys();
        $keys[] = Mage::app()->getStore()->getCurrentCurrencyCode();
        $block->setCacheKeys($keys);
    }
}