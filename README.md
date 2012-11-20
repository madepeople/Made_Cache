Magento Block Cache & Varnish extension
==

Why?
--
Few know that Magento out of the box actually doesn't cache any frontend blocks other than Navigation and Footer, which are basically static as they are. This module enhances performance by giving developers a simple interface for caching any block they want, and comes with good default settings.

Features
--
* Quick & Versatile Performance Boost
* Varnish + ESI support
* Unobtrusive & Future Proof
* Simple Configuration

Installation
--
Install this module using [modman](https://github.com/colinmollenhour/modman)

`modman clone git@github.com:madepeople/Made_Cache.git`

Or by downloading a copy from [Magento Connect](http://www.magentocommerce.com/magento-connect/made-cache-9281.html)

Basic Configuration
--
Most of the configuration is done via layout XML. In general it comes down to choosing which blocks to cache (or not), and which ones to fetch via ESI (or not).

For instance, to cache the products.list block on every cms\_page for 7200 seconds:

```xml
<layout version="0.1.0">
    <cms_page>
        <cache>
            <name lifetime="7200">products.list</name>
        </cache>
    <cms_page>
</layout>
```

These tags exist:

**cache** - Used to group which blocks should be cached

**nocache** - Used to group which block should _not_ be cached

**esi** (Varnish) - Generates an ESI tag in place of the block

**noesi** (Varnish) - Excludes a block from the ESI tag generation

**name** - Used inside the above to determine which blocks should be used

See [madecache.xml](https://github.com/madepeople/Made_Cache/blob/master/frontend/layout/madecache.xml) for more details.

Varnish & ESI
--
A custom magento.vcl file is available in the etc/ directory of the module. With Varnish in front and using this VCL, you can enable full page caching.

* Use magento.vcl with your Varnish instance and modify its IP settings in the top
* Set up your Varnish server's IP in System / Configuration / Made People / Cache
* Enable "Varnish" in the Magento Cache Management page
* Flush everything

The layout handle _varnish\_enabled_ is added to every request when Varnish is in front.


FAQ
==

Will Made\_Cache interfere with other modules?
--
Hopefully not. Events are used instead of block rewrites, and no core functionality is modified. This means that there will be less interference with other modules, and that manual block cache settings are preserved.

Another Varnish implementation?
--
That's right. The nice thing with this implementation is automatic ESI tag generation and session invalidation. We try to cache as much as we can without messing with standard installations. It also supports caching ESI requests on a user-level, meaning the majority of the requests come directly from Varnish (super fast).

License
--
This project is licensed under the 4-clause BSD License, see [LICENSE](https://github.com/madepeople/Made_Cache/blob/master/LICENSE)
