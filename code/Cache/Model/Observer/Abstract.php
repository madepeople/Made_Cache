<?php
/**
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
abstract class Made_Cache_Model_Observer_Abstract
{
    /**
     * Get Key for caching block content
     *
     * @see Mage_Core_Block_Abstract
     * @return string
     */
    protected function _getCacheKey(array $keyInfo, Mage_Core_Block_Abstract $block = null)
    {
        if ($block !== null) {
            $cacheKey = $block->getData('cache_key');
            if (!empty($cacheKey)) {
                $keyInfo = array($cacheKey);
            } else {
                $keyInfo = array_values($keyInfo);
            }
        }
        $key = implode('|', $keyInfo);
        $key = sha1($key);
        return $key;
    }

    /**
     * Get generic key array including handle etc, that all blocks use
     * 
     * @param Mage_Core_Block_Abstract $block
     * @return array 
     */
    protected function _getBasicKeys(Mage_Core_Block_Abstract $block)
    {
        $keys = $block->getCacheKeyInfo();
        if (!is_array($keys)) {
            $keys = array();
        }
        
        $keys[] = Mage::getSingleton('customer/session')->getCustomer()
                ->getGroupId();
        
        $keys[] = $block->getLayout()->getUpdate()->getCacheId();
        $keys[] = 'SSL_' . intval(!empty($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off') . '_';
        
        return $keys;
    }
}