<?php

/**
 * Inject cache variables depending on the layout cache ID. Typically the url
 * and everything with it
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Cacheid
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeys();
        $keys[] = $block->getLayout()->getUpdate()->getCacheId();
        $block->setCacheKeys($keys);
    }
}