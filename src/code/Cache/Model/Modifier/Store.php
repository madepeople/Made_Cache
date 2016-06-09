<?php

/**
 * Inject cache variables depending on store code + website code
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Store
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeys();
        $keys[] = Mage::app()->getWebsite()->getCode()
            . '_' . Mage::app()->getStore()->getCode();
        $block->setCacheKeys($keys);
    }
}