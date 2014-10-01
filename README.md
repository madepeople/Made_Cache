Magento Block Cache & Varnish extension
==

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
Install this module either by using [modman](https://github.com/colinmollenhour/modman)

`modman clone git@github.com:madepeople/Made_Cache.git`

Or using composer.

By downloading a copy from [Magento Connect](http://www.magentocommerce.com/magento-connect/made-cache-9281.html) (not always updated).

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

**cache** - Used to group which blocks should be cached

**nocache** - Used to group which block should _not_ be cached

**esi** (Varnish) - Generates an ESI tag in place of the block

**noesi** (Varnish) - Excludes a block from the ESI tag generation

**name** - Used inside the above to determine which blocks should be used

See [madecache.xml](https://github.com/madepeople/Made_Cache/blob/master/frontend/layout/madecache.xml) for more details.

Block Cache Modifiers
--
In order to keep the block caching flexible and allow for custom key generation and timeouts, we're using so called Modifier classes. This lets us apply the same cache for the main product list as for a custom block with products in it, for instance. Modifiers typically build the final caching key, which defines how granular the block should be cached.

The default modifiers are:

**cacheid** - The core cache id for the specific block

**store** - Cache one version per store

**currency** - Cache differently depending on currency

**groupid** - Use the group ID

**ssl** - SSL or no SSL, typically for blocks that include links

**blocktype** - Custom built in modifier that uses different methods for different type of core blocks. See [Model/Modifier/Blocktype](https://github.com/madepeople/Made_Cache/tree/master/src/code/Cache/Model/Modifier/Blocktype)

**request** - Use the request and it's parameters

Modifiers are also a nice way to cache differently depending on layout handles and so on.

Usage:

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

Varnish, ESI & Sessions
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

Sessions
--
Because Varnish sits in front of Magento itself, we need to have a way to validate sessions, otherwise Varnish has to pass every request to the backend as soon as a session is in place. Out of the box, this module adds a special cookie AJAX request to the bottom of the page which will send a new session if the visitor doesn't have one. This approach means that there will *always* be a request to the backend. The effect is that the user gets the idea of a super-fast loading page, but your backend still gets hit and might not be able to handle the thousands of requests you want it to.

It is technically possible for Varnish to check the actual session storage directly in the actual VCL, but this methodThere are different sessions storage mechanisms available for Magento. Each with their own drawbacks:

* **Files** - Hard to distribute on a network, and has locking issues
* **Memcache** - Fast, but no persistence and no locking
* **MySQL** - Has persistence and locking, but performance is subject to all query noise that Magento creates

Apart from these drawbacks, making Varnish to talk to the different storages isn't very straight forward.

Given the above, I have experimented with using Redis:

* Built-in optimistic locking
* In-memory with optional persistence
* [A Varnish Redis client exists](https://github.com/brandonwamboldt/libvmod-redis)
* Very fast

Varnish Session Validation
==

Initial Setup
--
We will be using Debian/Ubuntu steps for reference. First of all the sources to a built Varnish package need to exist since we want to build VMODs.

```bash
apt-get update
apt-get install build-essential dpkg-dev debhelper libedit-dev libncurses-dev libpcre3-dev python-docutils xsltproc libvarnishapi-dev autoconf automake autotools-dev libtool pkg-config

mkdir -p varnish/out
cd varnish
apt-get -b source varnish
```

**IMPORTANT!** If an apt-get upgrade also upgrades varnish, you *have* to recompile libvmod-curl again, using the whole procedure from `apt-get -b source varnish` and forward.

Redis Configuration
--
First, install and configure my [Redis session backend](https://github.com/madepeople/Made_Cache/blob/master/src/code/Cache/Redis/Session.php) and make sure it's working. After this, Varnish needs [libvmod-redis](https://github.com/brandonwamboldt/libvmod-redis) installed. For reference, here are Debian instructions:

```bash
apt-get install libhiredis-dev
git clone https://github.com/brandonwamboldt/libvmod-redis.git
cd libvmod-redis
./autogen.sh
./configure --prefix=$PWD/../out VARNISHSRC=../varnish-*
make
make install
```

With vmod-redis in place, search for "redis" in the magento.vcl file and uncomment and configure the affected lines. Then just restart Varnish and you should be good to go.

Config Cache Regeneration Locking
==
If you have a highly trafficled Magento store with many websites and store views, you're probably very afraid of flushing the cache. The reason for this is the time it takes to run [this method](https://github.com/OpenMage/magento-mirror/blob/magento-1.7/app/code/core/Mage/Core/Model/Resource/Config.php#L53) combined with the race conditions [here](https://github.com/OpenMage/magento-mirror/blob/magento-1.9/app/code/core/Mage/Core/Model/App.php#L413) and [here](https://github.com/OpenMage/magento-mirror/blob/magento-1.9/app/code/core/Mage/Core/Model/Config.php#L255). The Config model can be rewritten since [Magento 1.7](https://github.com/OpenMage/magento-mirror/blob/magento-1.7/app/Mage.php#L728) which is nice, but the App model has to be copied into app/code/local/. A version of the App model from 1.9.0.1/1.14.0.1 can be found [here](https://github.com/madepeople/Made_Cache/blob/feat_config_locking/src/code/Cache/Mage/Core/Model/App.php#L406-L478).

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
