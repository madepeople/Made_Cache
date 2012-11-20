<?php
/**
 * Handles arbitrary block rendering including different layout
 * handle definitions
 * 
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Layout extends Mage_Core_Model_Layout
{
    /**
     * Keep track of which blocks to cache
     * 
     * @var array
     */
    protected $_cacheBlocks = array();

    /**
     * Keep track of which blocks should be converted to ESI tags
     * 
     * @var array
     */
    protected $_esiBlocks = array();
    
    /**
     * Default cache lifetime
     * 
     * @var int
     */
    const DEFAULT_CACHE_LIFETIME = 9999999999;

    /**
     * Take cache/noache/ESI tags into concern for block rendering
     * 
     * @return Mage_Core_Model_Layout
     */
    public function generateXml()
    {
        parent::generateXml();
        $xml = $this->getNode();

        // Find blocks to cache
        $cacheList = $xml->xpath("//cache/*");
        if (count($cacheList)) {
            foreach ($cacheList as $node) {
                $lifetime = (int)$node->getAttribute('lifetime');
                if (empty($lifetime)) {
                    $lifetime = self::DEFAULT_CACHE_LIFETIME;
                }
                $this->_cacheBlocks[(string)$node] = $lifetime;
            }
        }
        
        // Find eventual nocache tags
        $noCacheList = $xml->xpath("//nocache/*");
        if (count($noCacheList)) {
            foreach ($noCacheList as $node) {
                $blockName = trim((string)$node);
                if (isset($this->_cacheBlocks[$blockName])) {
                    unset($this->_cacheBlocks[$blockName]);
                }
            }
        }
        
        // Find blocks that should be represented by ESI tags
        $esiList = $xml->xpath("//esi/*");
        if (count($esiList)) {
            foreach ($esiList as $node) {
                $blockName = trim((string)$node);
                // Names are unique, an array could hold future settings
                $this->_esiBlocks[$blockName] = array();
            }
        }
                
        // Find eventual noesi tags
        $noEsiList = $xml->xpath("//noesi/*");
        if (count($noEsiList)) {
            foreach ($noEsiList as $node) {
                $blockName = trim((string)$node);
                // Names are unique, an array could hold future settings
                if (isset($this->_esiBlocks[$blockName])) {
                    unset($this->_esiBlocks[$blockName]);
                }
            }
        }

        return $this;
    }
    
    /**
     * Create layout blocks hierarchy from layout xml configuration
     *
     * @param Mage_Core_Layout_Element|null $parent
     * @param boolean $parentIsMain  Render $parent first
     */
    public function generateBlocks($parent=null, $parentIsMain=false)
    {
        // Generate parent for single block definitions
        if ($parentIsMain !== false) {
            $this->_generateBlock($parent, new Varien_Object);
        }
        if (empty($parent)) {
            $parent = $this->getNode();
        }

        return parent::generateBlocks($parent);
    }
    
    /**
     * Generate cache key for block to be cached via layout XML
     * 
     * @param Varien_Simplexml_Element $node
     * @return string 
     */
    protected function _getCacheKey($node)
    {
        if (!empty($node['cache_key'])) {
            $cacheKey = (string)$node['cache_tags'];
        } else {
            $paramKeys = array();
            foreach (Mage::app()->getRequest()->getParams() as $key => $value) {
//                if (is_array($value)) {
//                    $value = implode('_', $value);
//                } elseif (is_object($value)) {
//                    $newValue = '';
//                    foreach ($value->getData() as $dataKey => $dataValue) {
//                        $newValue = $dataKey . $dataValue;
//                    }
//                    $value = $newValue;
//                }
                
                $value = Mage::helper('cache')->paramValueToCacheKey($value);
                $paramKeys[] = $key . $value;
            }
        
            $_customer = Mage::getSingleton('customer/session')->getCustomer();
            $cacheKey = (string)$node['name'] .
                $this->getUpdate()->getCacheId() .
                md5($_customer->getGroupId() .
                        join('_', $paramKeys));
        }
        return $cacheKey;
    }

    /**
     * Add block object to layout based on XML node data
     *
     * @param Varien_Simplexml_Element $node
     * @param Varien_Simplexml_Element $parent
     * @return Mage_Core_Model_Layout
     */
    protected function _generateBlock($node, $parent)
    {
        parent::_generateBlock($node, $parent);
        
        $blockName = (string)$node['name'];
        $block = $this->getBlock($blockName);
        if (!$block) {
            return $this;
        }
        
        if (in_array($blockName, array_keys($this->_cacheBlocks))) {
            $block->setData('cache_lifetime', $this->_cacheBlocks[$blockName]);
            $block->setData('cache_key', $this->_getCacheKey($node));
        }
        
        if (in_array($blockName, array_keys($this->_esiBlocks))) {
            $block->setData('esi', 1);
        }
        
        return $this;
    }
}
