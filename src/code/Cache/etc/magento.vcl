# Made_Cache Varnish 3 VCL
#
# https://github.com/madepeople/Made_Cache
#
import std;

backend default {
    .host = "127.0.0.1";
    .port = "8080";
}

# The admin backend needs longer timeout values
backend admin {
    .host = "127.0.0.1";
    .port = "8080";
    .first_byte_timeout = 18000s;
    .between_bytes_timeout = 18000s;
}

# Add additional (ie webserver) IPs here that should be able to purge cache
acl purge {
    "localhost";
    "127.0.0.1";
    "10.10.10.11";
    "10.10.10.10";
}

sub vcl_recv {
    # Purge specific object from the cache
    if (req.request == "PURGE")  {
        if (!client.ip ~ purge) {
            error 405 "Not allowed.";
        }
        return (lookup);
    }

    # Ban something
    if (req.request == "BAN") {
        # Same ACL check as above:
        if (!client.ip ~ purge) {
            error 405 "Not allowed.";
        }
        if (req.http.X-Ban-String) {
            ban(req.http.X-Ban-String);

            # Throw a synthetic page so the
            # request won't go to the backend.
            error 200 "Ban added";
        }

        error 400 "Bad request.";
    }

    # Flush the whole cache
    if (req.request == "FLUSH") {
        if (!client.ip ~ purge) {
            error 405 "Not allowed.";
        }
        ban("req.url ~ /");
        error 200 "Flushed";
    }

    # Refresh specific object
    if (req.request == "REFRESH") {
        if (!client.ip ~ purge) {
            error 405 "Not allowed.";
        }
        set req.request = "GET";
        set req.hash_always_miss = true;
    }

    # Switch to the admin backend
    if (req.http.Cookie ~ "adminhtml=") {
        set req.backend = admin;
    }

    # Pass anything other than GET and HEAD directly.
    if (req.request != "GET" && req.request != "HEAD") {
        # We only deal with GET and HEAD by default
        return (pass);
    }

    # Normalize Aceept-Encoding header to reduce vary
    # http://varnish.projects.linpro.no/wiki/FAQ/Compression
    if (req.http.Accept-Encoding) {
        if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv)$") {
            # No point in compressing these
            remove req.http.Accept-Encoding;
        } elsif (req.http.Accept-Encoding ~ "gzip") {
            set req.http.Accept-Encoding = "gzip";
        } elsif (req.http.Accept-Encoding ~ "deflate" && req.http.user-agent !~ "MSIE") {
            set req.http.Accept-Encoding = "deflate";
        } else {
            # Unknown algorithm
            remove req.http.Accept-Encoding;
        }
    }

    # Keep track of users with a session
    if (req.http.Cookie ~ "frontend=") {
        set req.http.X-Session-UUID =
            regsub(req.http.Cookie, ".*frontend=([^;]+).*", "\1");
    } else {
        # No frontend cookie, goes straight to the backend except if static assets.
        if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv|js|css)$") {
            return(lookup);
        }
        set req.http.X-Session-UUID = "";
    }

    return (lookup);
}

sub vcl_hash {
    # ESI Request
    if (req.url ~ "/madecache/varnish/(esi|messages)") {
        hash_data(regsub(req.url, "(/hash/[^\/]+/).*", "\1"));

        # Logged in user, cache on UUID level
        if (req.http.X-Session-UUID && req.http.X-Session-UUID != "") {
            hash_data(req.http.X-Session-UUID);
        }
    } else {
        hash_data(req.url);
    }

    if (req.http.X-Magento-Store && req.http.X-Magento-Store != "") {
        hash_data(req.http.X-Magento-Store);
    }

    # Also consider the host name for caching (multi-site with different themes etc)
    if (req.http.host) {
        hash_data(req.http.host);
    } else {
        hash_data(server.ip);
    }

    return (hash);
}

sub vcl_hit {
    if (req.request == "PURGE") {
        purge;
        error 200 "Purged";
    }
}

sub vcl_miss {
    if (req.request == "PURGE") {
        purge;
        error 404 "Not in cache";
    }
}

# Called when an object is fetched from the backend
sub vcl_fetch {
    # Pass the cookie requests directly to the backend, without caching
    if (req.url ~ "/madecache/varnish/cookie") {
        # Cache not to cache
        return (hit_for_pass);
    }

    # Hold down object variations by removing the referer and vary headers
    unset beresp.http.referer;
    unset beresp.http.vary;

    # If the X-Made-Cache-Ttl header is set, use it, otherwise default to
    # not caching the contents (0s)
    if (beresp.status == 200 || beresp.status == 301 || beresp.status == 404) {
        if (beresp.http.Content-Type ~ "text/html" || beresp.http.Content-Type ~ "text/xml") {
            set beresp.do_esi = true;
            set beresp.ttl = std.duration(beresp.http.X-Made-Cache-Ttl, 0s);

            # Don't cache expire headers, we maintain those differently
            unset beresp.http.expires;
        } elsif (beresp.http.Content-Type ~ "application/json") {
            set beresp.ttl = std.duration(beresp.http.X-Made-Cache-Ttl, 0s);
            unset beresp.http.expires;
        } else {
            # TTL for static content
            set beresp.ttl = 1w;
        }

        # Caching the cookie header would make multiple clients share session
        if (beresp.ttl > 0s) {
            set req.http.tempCookie = beresp.http.Set-Cookie;
            unset beresp.http.Set-Cookie;
        }

        # Cache (if positive TTL)
        return (deliver);
    }

    # Don't cache
    return (hit_for_pass);
}

sub vcl_deliver {
    # To debug if it's a hit or a miss
    set resp.http.Cache-Control = "no-store, no-cache, must-revalidate, post-check=0, pre-check=0";
    unset resp.http.X-Magento-Store;
    unset resp.http.X-Session-UUID;

    if (req.http.tempCookie) {
        # Version of https://www.varnish-cache.org/trac/wiki/VCLExampleLongerCaching
        set resp.http.Set-Cookie = req.http.tempCookie;
        set resp.http.age = "0";
    }

    return (deliver);
}
