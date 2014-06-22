<?php
/**
 * Contains globally used helper functions
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Helper_Data extends Mage_Core_Helper_Abstract
{
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
        $layout = Mage::app()->getFrontController()->getAction()
                ->getLayout();
        
        foreach (array('global_messages', 'messages') as $blockName) {
            if (($messagesBlock = $layout->getBlock($blockName)) !== false) {
                if ($messagesBlock->getMessageCollection()->count()) {
                    return true;
                }
            }
        }
        
        return (bool)Mage::getModel('core/message_collection')
                ->count();
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