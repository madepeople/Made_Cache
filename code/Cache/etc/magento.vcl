import std;

# Uncomment this is you've compiled the libvmod-curl extension and have
# CouchDB session management set up. Also, search for "curl" in this file
# and set the rest up
#import curl;

backend default {
    .host = "127.0.0.1";
    .port = "9000";
}

# The admin backend needs longer timeout values
backend admin {
    .host = "127.0.0.1";
    .port = "9000";
    .first_byte_timeout = 18000s;
    .between_bytes_timeout = 18000s;
}

# Add additional (ie webserver) IPs here that should be able to purge cache
acl purge {
    "localhost";
    "127.0.0.1";
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

    # Keep track of logged in users
    if (req.http.Cookie ~ "frontend=") {
        set req.http.X-Session-UUID =
            regsub(req.http.Cookie, ".*frontend=([^;]+).*", "\1");

        # This is where we have to check for session validity. Needs the curl vmod
        # to be installed and imported at the top of this file. If the session is
        # invalid we pass the user to the backend. Your CouchDB URL has to be
        # defined manually here, in the form:
        #
        #   http://couchdb.url.or.ip:port/magento_session/_design/misc/_show/is_session_valid/SESSION_ID_FROM_REQUEST
        #
        # The following show function needs to be defined in CouchDB as well:
        #
        #   https://gist.github.com/jonathanselander/1c71f413911116ecba11
        #
        #if (!(req.url ~ "\.(css|js|jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv)$")) {
        #    curl.fetch("http://127.0.0.1:5984/magento_session/_design/misc/_show/is_session_valid/" + req.http.X-Session-UUID);
        #    if (curl.body() != "true") {
        #        curl.free();
        #        return(pass);
        #    }
        #    curl.free();
        #}
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
            # unkown algorithm
            remove req.http.Accept-Encoding;
        }
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

    # If the X-Made-Cache-Ttl header is set, use it, otherwise default to
    # not caching the contents (0s)
    if (beresp.status == 200 || beresp.status == 301 || beresp.status == 404) {
        if (beresp.http.Content-Type ~ "text/html" || beresp.http.Content-Type ~ "text/xml") {
            set beresp.do_esi = true;
            set beresp.ttl = std.duration(beresp.http.X-Made-Cache-Ttl, 0s);
        } else {
            # TTL for static content
            set beresp.ttl = 1w;
        }

        # Don't cache expire headers, we maintain those differently
        unset beresp.http.expires;

        # Hold down object variations by removing the referer header
        unset beresp.http.referer;

        # We let Magento determine variations
        unset beresp.http.vary;

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

    if (req.http.tempCookie) {
        # Version of https://www.varnish-cache.org/trac/wiki/VCLExampleLongerCaching
        set resp.http.Set-Cookie = req.http.tempCookie;
        set resp.http.age = "0";
    }

    return (deliver);
}
