<?php

/**
 * @author jonathan@madepeople.se
 */
class Made_Cache_Model_Core_Store extends Mage_Core_Model_Store
{
    /**
     * Initialize base store configuration data
     *
     * Method provide cache configuration data without loading store config XML
     *
     * Overridden to prevent slave lag cache inconsistency
     *
     * @return Mage_Core_Model_Config
     */
    public function initConfigCache()
    {
        /**
         * Funtionality related with config separation
         */
        if ($this->_configCache === null) {
            $code = $this->getCode();
            if ($code) {
                if (Mage::app()->useCache('config')) {
                    $backend = Mage::app()->getCacheInstance()
                        ->getFrontend()
                        ->getBackend();
                    
                    if (get_class($backend) !== 'Made_Cache_Redis_Backend') {
                        return parent::initConfigCache();
                    }

                    $readClient = $backend->getReadClient();
                    $writeClient = $backend->getWriteClient();
                    $backend->setReadClient($writeClient);
                    $cacheId = 'store_' . $code . '_config_cache';
                    $data = Mage::app()->loadCache($cacheId);
                    $backend->setReadClient($readClient);
                    if ($data) {
                        $data = unserialize($data);
                    } else {
                        $data = array();
                        foreach ($this->_configCacheBaseNodes as $node) {
                            $data[$node] = $this->getConfig($node);
                        }
                        Mage::app()->saveCache(serialize($data), $cacheId, array(
                            self::CACHE_TAG,
                            Mage_Core_Model_Config::CACHE_TAG
                        ));
                    }
                    $this->_configCache = $data;
                }
            }
        }
        return $this;
    }
}