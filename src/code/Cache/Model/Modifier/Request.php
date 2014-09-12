
<?php

/**
 * Inject cache variables depending on request parameters
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Request
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $helper = Mage::helper('cache');
        $keys = $block->getCacheKeys();
        foreach (Mage::app()->getRequest()->getParams() as $key => $value) {
            $value = $helper->paramValueToCacheKey($value);
            $keys[] = $key . '_' . $value;
        }
        $block->setCacheKeys($keys);
    }
}