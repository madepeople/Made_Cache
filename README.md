Magento Block Cache & Varnish extension
==

ABC?
--
Short for "Advanced Block Cache". We now have Varnish as well - an acronym makes sense.

Why?
--
Few know that Magento out of the box actually doesn't cache any frontend blocks other than Navigation and Footer, which are basically static as they are. This module enhances performance by giving developers a simple interface for caching any block they want, and comes with good default settings.

Features
--
* Quick & Versatile Performance Boost
* Varnish + ESI support
* Unobtrusive & Future Proof
* Simple Configuration

FAQ
==

Will ABC interfere with other modules?
--
Hopefully not. Events are used instead of block rewrites, and no core functionality is modified. This means that there will be less interference with other modules, and that manual block cache settings are preserved.

Another Varnish implementation?
--
That's right. The nice thing with this implementation is automatic ESI tag generation and session invalidation. We try to cache as much as we can without messing with standard installations.
