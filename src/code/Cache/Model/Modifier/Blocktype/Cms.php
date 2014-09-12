<?php

/**
 * Inject cache variables for cms blocks
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Blocktype_Cms
{
    /**
     * CMS Page cache
     *
     * @param Mage_Cms_Block_Page $block
     */
    public function applyCmsPage(Mage_Cms_Block_Page $block)
    {
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }

        // Set cache tags
        $tags = $block->getCacheTags();
        $tags[] = Mage_Cms_Model_Page::CACHE_TAG . '_' .
            $block->getPage()->getId();
        $block->setData('cache_tags', $tags);

        // Set cache keys
        $keys = $block->getCacheKeys();
        $keys[] = $block->getPage()->getId();
        $keys[] = $block->getLayout()->getUpdate()->getCacheId();
        $block->setData('cache_keys', $keys);
    }

    /**
     * CMS block cache, must use the block id from the database
     *
     * @param type $block
     */
    public function applyCmsBlock($block)
    {
        // The "messages" block is session-dependent, don't cache
        if (Mage::helper('cache')->responseHasMessages()) {
            $block->setData('cache_lifetime', null);
            return;
        }

        // Set cache tags
        $tags = array();

        $blockId = $block->getData('block_id');;
        if ($blockId) {
            $cmsBlock = Mage::getModel('cms/block')
                ->setStoreId(Mage::app()->getStore()->getId())
                ->load($blockId);
            if ($cmsBlock->getIsActive()) {
                $tags = $block->getCacheTags();
                $tags[] = Mage_Cms_Model_Block::CACHE_TAG . '_' .
                    $cmsBlock->getId();
            }
        }
        $block->setData('cache_tags', $tags);

        // Set cache key
        $keys = $block->getCacheKeys();

        $blockId = $block->getData('block_id');;
        if ($blockId) {
            $cmsBlock = Mage::getModel('cms/block')
                ->setStoreId(Mage::app()->getStore()->getId())
                ->load($blockId);
            if ($cmsBlock->getIsActive()) {
                $keys = $block->getCacheKeyInfo();

                if (!is_array($keys)) {
                    $keys = array();
                }

                $keys[] = $blockId;
                $keys[] = $block->getLayout()->getUpdate()->getCacheId();
            }
        }
        $block->setData('cache_keys', $keys);
    }
}