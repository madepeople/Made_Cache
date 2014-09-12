<?php

/**
 * Inject cache variables depending on customer group ID
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Groupid
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeys();
        $keys[] = Mage::getSingleton('customer/session')->getCustomer()
            ->getGroupId();
        $block->setCacheKeys($keys);
    }
}