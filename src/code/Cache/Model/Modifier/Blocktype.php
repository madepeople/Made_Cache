<?php

/**
 * Inject cache variables for different types of core blocks
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2014 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Modifier_Blocktype
    implements Made_Cache_Model_Modifier_Interface
{
    public function apply(Mage_Core_Block_Abstract $block)
    {
        switch (true) {
            case $block instanceof Mage_Catalog_Block_Product_Abstract:
                Mage::getSingleton('cache/modifier_blocktype_catalog')
                    ->applyProductView($block);
                break;
            case $block instanceof Mage_Catalog_Block_Category_View:
                Mage::getSingleton('cache/modifier_blocktype_catalog')
                    ->applyCategoryView($block);
                break;
            case $block instanceof Mage_Catalog_Block_Layer_View:
                Mage::getSingleton('cache/modifier_blocktype_catalog')
                    ->applyCatalogLayerView($block);
                break;
            case $block instanceof Mage_CatalogSearch_Block_Advanced_Result:
                Mage::getSingleton('cache/modifier_blocktype_catalog')
                    ->applySearchResult($block);
                break;
            case $block instanceof Mage_Catalog_Block_Product_List:
                Mage::getSingleton('cache/modifier_blocktype_catalog')
                    ->applyProductList($block);
                break;
            case $block instanceof Mage_Cms_Block_Page:
                Mage::getSingleton('cache/modifier_blocktype_cms')
                    ->applyCmsPage($block);
                break;
            case $block instanceof Mage_Cms_Block_Block:
            case $block instanceof Mage_Cms_Block_Widget_Block:
                Mage::getSingleton('cache/modifier_blocktype_cms')
                    ->applyCmsBlock($block);
                break;
            case $block instanceof Mage_Checkout_Block_Cart_Abstract:
                Mage::getSingleton('cache/modifier_blocktype_checkout')
                    ->applyCartSidebar($block);
                break;
        }
    }
}