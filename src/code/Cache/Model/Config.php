<?php

/**
 * Custom config model that leverages a redis lock to prevent config generation
 * DDoS, typically a problem when you have many store views and a high amount
 * of visitors.
 *
 * Also depends on a local copy of Mage_Core_Model_App put in app/code/local.
 * The reason for this local override is that the app class _initModules()
 * needs locking as well, otherwise everything arbitrary will break. Quite
 * annoying that this is the way it's made, but hey, we have no say.
 *
 * @author jonathan@madepeople.se
 */
class Made_Cache_Model_Config extends Mage_Core_Model_Config
{

    /**
     * Used to track which process is allowed to save cache.
     */
    const SAVE_KEY = 'cachegen';

    /**
     * Reinitialize configuration. Instead of doing the actual reinitialization
     * we mark the cache as invalidated and set a timestamp that is later
     * matched with a cron scheduled config cache regenerator timestamp.
     *
     * @param   array $options
     * @return  Mage_Core_Model_Config
     */
    public function reinit($options = array())
    {
        $backend = Mage::app()->getCacheInstance()
            ->getFrontend()
            ->getBackend();

        if (get_class($backend) !== 'Made_Cache_Redis_Backend') {
            return parent::reinit($options);
        }

        if (in_array(self::SAVE_KEY, $options)) {
            // Mark the config cache as valid before we lock to prevent an
            // invalidate-regenerate race condition
            $cache = Mage::app()->getCacheInstance();
            $types = $cache->load(Mage_Core_Model_Cache::INVALIDATED_TYPES);
            if ($types) {
                $types = unserialize($types);
                if (isset($types[strtolower(self::CACHE_TAG)])) {
                    unset($types[strtolower(self::CACHE_TAG)]);
                }
                $cache->save(serialize($types), Mage_Core_Model_Cache::INVALIDATED_TYPES);
            }

            // If we got called by our observer, pass it on with a single
            // instance lock.
            $options = $this->getLockingOptions();
            if ($backend->acquireLock($options['lock_name'], $options['token'], $options['lock_timeout'])) {
                $this->_allowCacheForInit = false;
                $this->_useCache = false;
                $options['lock_acquired'] = true;
                $this->init($options);
                $backend->releaseLock($options['lock_name'], $options['token']);
            }
        } else {
            // Instead of reinitializing the cache, simply mark the config
            // cache as invalidated.
            Mage::app()->getCacheInstance()
                ->invalidateType(strtolower(self::CACHE_TAG));
        }

        return $this;
    }

    /**
     * Initialization of core configuration
     *
     * @return Mage_Core_Model_Config
     */
    public function init($options=array())
    {
        $backend = Mage::app()->getCacheInstance()
            ->getFrontend()
            ->getBackend();

        if (get_class($backend) !== 'Made_Cache_Redis_Backend') {
            return parent::init($options);
        }

        $this->setCacheChecksum(null);
        $this->_cacheLoadedSections = array();
        $this->setOptions($options);
        $this->loadBase();

        $cacheLoad = $this->loadModulesCache();
        if ($cacheLoad) {
            return $this;
        }

        $lockingOptions = $this->getLockingOptions();

        $lockSpun = false;
        if (empty($options['lock_acquired'])) {
            // Spin lock
            while (!$backend->acquireLock($lockingOptions['lock_name'], $lockingOptions['token'], $lockingOptions['lock_timeout'])) {
                $lockSpun = true;
                usleep($lockingOptions['spin_timeout']);
            }
        }

        if ($lockSpun) {
            // The cache might have been generated while we waited for the lock
            $cacheLoad = $this->loadModulesCache();
            if ($cacheLoad) {
                $backend->releaseLock($lockingOptions['lock_name'], $lockingOptions['token']);
                return $this;
            }
        }

        // Check cache

        // @see https://github.com/convenient/magento-ce-ee-config-corruption-bug#the-fix
        $this->_useCache = false;
        // </iterations fix>
        $this->loadModules();
        $this->loadDb();
        $this->saveCache();

        if (empty($options['lock_acquired'])) {
            $backend->releaseLock($lockingOptions['lock_name'], $lockingOptions['token']);
        }

        return $this;
    }

    /**
     * Remove configuration cache. If the observer is saving the cache we
     * actually don't want to remove it, just replace it. Removal of the cache
     * is done from the cache management screen.
     *
     * @return Mage_Core_Model_Config
     */
    public function removeCache()
    {
        if (Mage::registry(self::SAVE_KEY)) {
            return $this;
        }
        Mage::app()->cleanCache(array(self::CACHE_TAG));
        return parent::removeCache();
    }

    /**
     * Parameters used for locking the config cache generation
     *
     * @return array
     */
    public function getLockingOptions()
    {
        $options = array(
            'lock_name' => 'lock.config_init',
            'lock_timeout' => 30, // In seconds
            'spin_timeout' => 100000 // 100 ms, uses usleep()
        );
        $options['token'] = md5($options['lock_name']);
        return $options;
    }

}
