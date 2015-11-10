Magento Block Cache & Varnish extension
==

<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->

  - [Why?](#why)
  - [Features](#features)
  - [Installation](#installation)
  - [Magento Configuration](#magento-configuration)
  - [Block Cache Modifiers](#block-cache-modifiers)
- [Redis Cache & Sessions](#redis-cache-&-sessions)
  - [Configuration](#configuration)
- [Varnish & ESI](#varnish-&-esi)
  - [ESI](#esi)
  - [General Configuration](#general-configuration)
- [Config Cache Regeneration Locking](#config-cache-regeneration-locking)
- [FAQ](#faq)
  - [Will Made\_Cache interfere with other modules?](#will-made\_cache-interfere-with-other-modules)
  - [Another Varnish implementation?](#another-varnish-implementation)
  - [License](#license)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

Why?
--
Few know that Magento out of the box actually doesn't cache any frontend blocks other than Navigation and Footer, which are basically static as they are. This module enhances performance by giving developers a simple interface for caching any block they want, and comes with good default settings.

Features
--
* Quick & Versatile Performance Boost
* Varnish + ESI support
* High-performance Redis cache & session backends
* Configuration regeneration locking
* Unobtrusive & Future Proof
* Simple Configuration

Installation
--
Install this module either by:

1. Using [modman](https://github.com/colinmollenhour/modman)

	`modman clone git@github.com:madepeople/Made_Cache.git`

2. Using [composer](https://github.com/magento-hackathon/magento-composer-installer)

3. Downloading a copy from [Magento Connect](http://www.magentocommerce.com/magento-connect/made-cache-9281.html) (not always updated)

Magento Configuration
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

* `cache`: Used to group which blocks should be cached
* `nocache`: Used to group which block should _not_ be cached
* `esi (Varnish)`: Generates an ESI tag in place of the block
* `noesi (Varnish)`: Excludes a block from the ESI tag generation
* `name`: Used inside the above to determine which blocks should be used
 
See [madecache.xml](https://github.com/madepeople/Made_Cache/blob/master/frontend/layout/madecache.xml) for more details.

Block Cache Modifiers
--
In order to keep the block caching flexible and allow for custom key generation and timeouts, we're using so called Modifier classes. This lets us apply the same cache for the main product list as for a custom block with products in it, for instance. Modifiers typically build the final caching key, which defines how granular the block should be cached.

The default modifiers are:

* `cacheid`: The core cache id for the specific block
* `store`: Cache one version per store
* `currency`: Cache differently depending on currency
* `groupid`: Use the group ID
* `ssl`: SSL or no SSL, typically for blocks that include links
* `blocktype`: Custom built in modifier that uses different methods for different type of core blocks. See [Model/Modifier/Blocktype](https://github.com/madepeople/Made_Cache/tree/master/src/code/Cache/Model/Modifier/Blocktype)
* `request`: Use the request and its parameters
* `session`: Use the session ID

Modifiers are also a nice way to cache differently depending on layout handles and so on.

Set it up like this:

```xml
<layout version="0.1.0">
    <default>
        <cache>
            <name modifiers="store currency">block_that_differs_depending_on_store_and_currency</name>
        </cache>
    <default>
</layout>
```

Custom modifiers can be defined [like this](https://github.com/madepeople/Made_Cache/blob/master/src/code/Cache/etc/config.xml#L25).

Redis Cache & Sessions
==
In order to have full control over the caching, locking and sessions, I have developed custom implementations of these backends. The existing solutions suffer from locking timeouts and race conditions, as well as need garbage collection. This didn't suit me and gave me strange issues with load balancing.

Configuration
--

To enable the cache and/or session handler, edit your local.xml:

```xml
<config>
	<global>
		<!-- ... -->
		
		<!--
        <session_save><![CDATA[files]]></session_save>
        -->
        <session_save><![CDATA[db]]></session_save>
        <models>
            <core_resource>
                <rewrite>
                    <session>Made_Cache_Redis_Session</session>
                </rewrite>
            </core_resource>
        </models>
        
        <!-- Optional settings with defaults
        <redis_session>
            <hostname>127.0.0.1</hostname>
            <database>2</database>
            <prefix></prefix>
            <port>6379</port>
        </redis_session>
        -->
        
        <cache>
            <backend>Made_Cache_Redis_Backend</backend>
            <!-- Optional settings -->
            <backend_options>            
                <hostname>127.0.0.1</hostname>
                <database>0</database>
                <prefix></prefix>
                <port>6379</port>
            </backend_options>
        </cache>
        
        <!-- For Enterprise Edition >= 1.11 -->
        <full_page_cache>
            <backend>Made_Cache_Redis_Backend</backend>
            <!-- Optional settings -->
            <backend_options>
                <hostname>127.0.0.1</hostname>
                <database>1</database>
                <prefix></prefix>
                <port>6379</port>
            </backend_options>            
        </full_page_cache>
        
        <!-- ... -->
    </global>
</config>
```

It's recommended to set up the three different settings on completely different redis instances, since sessions should persist and cache generally shouldn't. Also, cache needs different memory limit/purge settngs. Also, you don't want a cache "FLUSHALL" to remove all sessions.

Varnish & ESI
==
A custom magento.vcl file is available in the etc/ directory of the module. With Varnish in front and using this VCL, you can harness full page caching.

ESI
--
In order to handle dynamic user-dependent blocks, something called ESI (Edge Side Includes) is used. With this in place, Varnish makes an extra request to the backend for each dynamic block, such as the cart, compared items, etc.

General Configuration
--

* Use magento.vcl with your Varnish instance and modify its IP settings in the top
* For ESI to work properly, it's a good idea to add `-p esi_syntax=0x1` to the Varnish command line
* Set up your Varnish server's IP in System / Configuration / Made People / Cache
* Enable "Varnish" in the Magento Cache Management page
* Flush everything

The layout handle _varnish\_enabled_ is added to every request when Varnish is in front.

Config Cache Regeneration Locking
==
If you have a highly trafficked Magento store with many websites and store views, you're probably very afraid of flushing the cache. The reason for this is the time it takes to run [this method](https://github.com/OpenMage/magento-mirror/blob/magento-1.7/app/code/core/Mage/Core/Model/Resource/Config.php#L53) combined with the race conditions [here](https://github.com/OpenMage/magento-mirror/blob/magento-1.9/app/code/core/Mage/Core/Model/App.php#L413) and [here](https://github.com/OpenMage/magento-mirror/blob/magento-1.9/app/code/core/Mage/Core/Model/Config.php#L255). The Config model can be rewritten since [Magento 1.7](https://github.com/OpenMage/magento-mirror/blob/magento-1.7/app/Mage.php#L728) which is nice, but the App model has to be copied into app/code/local/. A version of the App model from 1.9.0.1/1.14.0.1 can be found [here](https://github.com/madepeople/Made_Cache/blob/feat_config_locking/src/code/Cache/Mage/Core/Model/App.php#L406-L478).

Also, the bottom of index.php needs to be modified to use the custom Config model, like this:

```php
Mage::run($mageRunCode, $mageRunType, array(
    'config_model' => 'Made_Cache_Model_Config'
));
```

The values of `spin_timeout` and `lock_timeout` can be adjusted to a level that works with the amount of visitors and the time it takes to regenerate the configuration tree.

So far this is a single instance lock in Redis, which does the job and lets us load balance. For super high performance with load balancing, a [distributed lock](http://redis.io/topics/distlock) should be implemented instead.

FAQ
==

Will Made\_Cache interfere with other modules?
--
Hopefully not. Events are used instead of block rewrites, and only one core model is rewritten, in a non-aggressive way. This means that there will be less interference with other modules, and that manual block cache settings are preserved.

Another Varnish implementation?
--
That's right. The nice thing with this implementation is automatic ESI tag generation and session invalidation. We try to cache as much as we can without messing with standard installations. It also supports caching ESI requests on a user-level, meaning the majority of the requests come directly from Varnish (super fast).

License
--
This project is licensed under the 4-clause BSD License, see [LICENSE](https://github.com/madepeople/Made_Cache/blob/master/LICENSE)
