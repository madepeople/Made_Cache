<?php
/**
 * Inject cache variables for arbitrary blocks
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer_Default
    extends Made_Cache_Model_Observer_Abstract
{
    /**
     * Any block, uses the default values
     *
     * @param Mage_Catalog_Block_Product_View $block
     */
    public function applyDefault($block)
    {
        // Set cache keys
        $keys = $this->_getBasicKeys($block);
        $block->setData('cache_key', $this->_getCacheKey($keys, $block));
    }
}