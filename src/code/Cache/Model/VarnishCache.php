<?php
/**
 * Method that instantiates and accesses the Varnish redis instance separately
 * from the main cache instance. We use this to store tags directly in
 * redis. Inspired by EE FPC
 *
 * @author jonathan@madepeople.se
 */
class Made_Cache_Model_VarnishCache
{

    /**
     * Varnish cache instance
     *
     * @var Mage_Core_Model_Cache
     */
    static protected $_cache = null;

    /**
     * Cache instance static getter
     *
     * @return Mage_Core_Model_Cache
     */
    static public function getCacheInstance()
    {
        if (is_null(self::$_cache)) {
            $options = Mage::app()->getConfig()->getNode('global/cache_varnish');
            if (empty($options)) {
                self::$_cache = false;
                return false;
            } else {
                $options = $options->asArray();
                self::$_cache = Mage::getModel('core/cache', $options);
            }
        }

        return self::$_cache;
    }
}
