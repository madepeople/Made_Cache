<?php
/**
 * Use this for granular product list cache
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Block_Catalog_Product_List extends Mage_Catalog_Block_Product_List
{
    /**
     * For granular caching of product list blocks. Requires the markup
     * of a single product to be broken out of list.phtml into
     * catalog/product/list/product.phtml
     *
     * @param Mage_Catalog_Model_Product $product
     * @return string
     */
    public function getProductHtml($product)
    {
        // Prevent crash within catalog_product_view
        if (($viewedProduct = Mage::registry('product')) !== null) {
            Mage::unregister('product');
        }

        $block = $this->getLayout()
                ->createBlock('cache/catalog_product_list_product')
                ->setCacheLifetime($this->getCacheLifetime())
                ->setTemplate('catalog/product/list/product.phtml')
                ->setProduct($product);

        $html = $block->toHtml();
        Mage::register('product', $viewedProduct);
        return $html;
    }
}
