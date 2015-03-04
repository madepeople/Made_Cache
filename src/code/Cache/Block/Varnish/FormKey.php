<?php

/**
 * The purpose of this block is to return the session CSRF key to be used
 * in ESI requests to allow CSRF for Varnish cached pages
 *
 * @author jonathan@madepeople.se
 */
class Made_Cache_Block_Varnish_FormKey extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $formKey = Mage::getSingleton('core/session')->getFormKey();
        return $formKey;
    }
}