<?php

/**
 * Contains globally used helper functions
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Helper_Data extends Mage_Core_Helper_Abstract
{

    const XML_PATH_MODIFIERS    = 'global/cache/block/modifiers';

    protected $_defaultModifiers = 'cacheid currency groupid ssl blocktype request';

    /**
     * Get block cache modifiers by type
     *
     * @param string $type
     * @return array
     */
    public function getModifiersByType($type)
    {
        $path = self::XML_PATH_MODIFIERS . '/' . $type;
        $modifiersConfig = Mage::getConfig()->getNode($path);
        if ($modifiersConfig) {
            $modifiers = (string) $modifiersConfig;
            $modifiers = explode(',', $modifiers);
        } else {
            $modifiers = false;
        }
        return $modifiers;
    }

    /**
     * Get the classes used to modify block cache variables
     *
     * @param Mage_Core_Block_Abstract $block
     * @return array
     */
    public function getBlockModifiers(Mage_Core_Block_Abstract $block)
    {
        $modifiers = $block->getCacheModifiers();
        if (empty($modifiers) || $modifiers === 'default') {
            $modifiers = $this->_defaultModifiers;
        }
        return explode(' ', $modifiers);
    }

    /**
     * Get the final block key used for caching
     *
     * @param Mage_Core_Block_Abstract $block
     * @return string
     */
    public function getBlockKey(Mage_Core_Block_Abstract $block)
    {
        $cacheKeys = array_values($block->getData('cache_keys'));
        $key = implode('|', $cacheKeys);
        $key = sha1($key);
        return $key;
    }

    /**
     * Flattens array
     *
     * @param type $array
     * @return string
     */
    protected function _flattenArray($array)
    {
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, $this->_flattenArray($value));
            }
            else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Returns string usable as a cache key part, and takes different
     * datatypes into concern
     *
     * @param mixed $value
     * @return string
     */
    public function paramValueToCacheKey($value)
    {
        if (is_array($value)) {
            $value = implode('_', $this->_flattenArray($value));
        } else if (is_object($value)) {
            $newValue = '';
            foreach ($value->getData() as $dataKey => $dataValue) {
                $newValue = $dataKey . $dataValue;
            }
            $value = $newValue;
        }

        return $value;
    }

    /**
     * Used to determine if the current response has notification messages,
     * because if it does, neither the block cache or varnish should keep it.
     *
     * @return boolean
     */
    public function responseHasMessages()
    {
        $frontController = Mage::app()->getFrontController();
        if (!$frontController->hasAction()) {
            return false;
        }

        $layout = $frontController->getAction()->getLayout();

        foreach (array('global_messages', 'messages') as $blockName) {
            if (($messagesBlock = $layout->getBlock($blockName)) !== false) {
                if ($messagesBlock->getMessageCollection()->count()) {
                    return true;
                }
            }
        }

        // Loop over the session thing and see if there are message collections
        // with messages in them
        foreach ($_SESSION as $key => $data) {
            if (isset($data['messages'])
                && $data['messages'] instanceof Mage_Core_Model_Message_Collection) {
                $messages = $data['messages'];
                if ($messages->count()) {
                    return true;
                }
            }
        }

        return (bool)Mage::getModel('core/message_collection')
            ->count();
    }

    /**
     * Clear all messages stored in the session to prevent multiple prints of
     * the same message
     */
    public function clearMessages()
    {
        foreach ($_SESSION as $key => $data) {
            if (isset($data['messages'])
                && $data['messages'] instanceof Mage_Core_Model_Message_Collection) {
                $messages = $data['messages'];
                if ($messages->count()) {
                    $messages->clear();
                }
            }
        }
    }

    /**
     * Get product IDs for related products (useful when generating cache tags)
     *
     * @param array $productIds
     * @return array
     */
    public function getChildProductIds($productIds)
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $select = $read->select()
            ->from($resource->getTableName('catalog/product_super_link'), array('product_id'))
            ->where('parent_id IN(?)', $productIds);

        $childIds = array();
        foreach ($read->fetchAll($select) AS $link) {
            $childIds[] = $link['product_id'];
        }
        return $childIds;
    }
}
