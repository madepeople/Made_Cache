<?php

/**
 * Inject cache variables depending on if the store is running SSL or not
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Ssl
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeys();
        $keys[] = 'SSL_' . intval(!empty($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off') . '_';
        $block->setCacheKeys($keys);
    }
}