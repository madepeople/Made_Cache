# Made_Cache Varnish 4 VCL
#
# https://github.com/madepeople/Made_Cache
#
vcl 4.0;
import std;

backend default {
    .host = "127.0.0.1";
    .port = "8080";
    .first_byte_timeout = 300s;
    .between_bytes_timeout = 300s;
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

# List of upstream proxies we trust to set X-Forwarded-For correctly.
acl upstream_proxy {
    "127.0.0.1";
}

# List of IPs we want to block
acl abuse {
}

sub vcl_recv {

    # Make sure we get the real IP to the backend
    if (client.ip ~ upstream_proxy && req.http.X-Forwarded-For) {
        set req.http.X-Forwarded-For = req.http.X-Real-IP;
    } else {
        set req.http.X-Forwarded-For = regsub(client.ip, ":.*", "");
    }

    # Don't allow abused IPs
    if (client.ip ~ abuse) {
        return (synth(403, "Abuse from this IP detected. You are now blocked."));
    }

    # Purge specific object from the cache
    if (req.method == "PURGE")  {
        if (!client.ip ~ purge) {
            return (synth(403, "Not allowed."));
        }
        return (purge);
    }

    # Ban something
    if (req.method == "BAN") {
        # Same ACL check as above:
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed."));
        }
        if (req.http.X-Ban-String) {
            ban(req.http.X-Ban-String);

            # Throw a synthetic page so the
            # request won't go to the backend.
            return (synth(200, "Ban added"));
        }

        return (synth(400, "Bad request."));
    }

    # Flush the whole cache
    if (req.method == "FLUSH") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed."));
        }
        ban("req.url ~ /");
        return (synth(200, "Flushed"));
    }

    # Refresh specific object
    if (req.method == "REFRESH") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed."));
        }
        set req.method = "GET";
        set req.hash_always_miss = true;
    }

    # Switch to the admin backend
    if (req.http.Cookie ~ "adminhtml=") {
        set req.backend_hint = admin;
    }

    # Pass anything other than GET and HEAD directly.
    if (req.method != "GET" && req.method != "HEAD") {
        # We only deal with GET and HEAD by default
        return (pass);
    }

    # Pass checkout requests directly
    if (req.url ~ "/(streamcheckout|checkout)/") {
        return (pass);
    }

    # Normalize Aceept-Encoding header to reduce vary
    # http://varnish.projects.linpro.no/wiki/FAQ/Compression
    if (req.http.Accept-Encoding) {
        if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv)$") {
            # No point in compressing these
            unset req.http.Accept-Encoding;
        } elsif (req.http.Accept-Encoding ~ "gzip") {
            set req.http.Accept-Encoding = "gzip";
        } elsif (req.http.Accept-Encoding ~ "deflate" && req.http.user-agent !~ "MSIE") {
            set req.http.Accept-Encoding = "deflate";
        } else {
            # Unknown algorithm
            unset req.http.Accept-Encoding;
        }
    }

    # Keep track of users with a session
    if (req.http.Cookie ~ "frontend=") {
        set req.http.X-Session-UUID =
            regsub(req.http.Cookie, ".*frontend=([^;]+).*", "\1");
    } else {
        # No frontend cookie, goes straight to the backend except if static assets.
        if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv|js|css)$") {
            return(hash);
        }
        set req.http.X-Session-UUID = "";
    }

    return (hash);
}

sub vcl_hash {
    # ESI Request
    if (req.url ~ "/madecache/varnish/(esi|messages|cookie)") {
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

    # Include the X-Forward-Proto header, since we want to treat HTTPS
    # requests differently, and make sure this header is always passed
    # properly to the backend server.
    if (req.http.X-Forwarded-Proto) {
        hash_data(req.http.X-Forwarded-Proto);
    }

    return (lookup);
}

# Called when an object is fetched from the backend
sub vcl_backend_response {

    # Strip Cookies from static assets.
    if (bereq.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv|js|css)$") {
        set beresp.ttl = 1w;
    }

    if (bereq.url ~ "\.(css|js)$") {
        set beresp.do_gzip = true;
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
        } else {
            # TTL for static content
            set beresp.ttl = 1w;
        }

        # Caching the cookie header would make multiple clients share session
        if (beresp.ttl > 0s) {
            unset beresp.http.Set-Cookie;
        }

        # Allow us to ban on object URL
        set beresp.http.url = bereq.url;

        # Cache (if positive TTL)
        return (deliver);
    }

    # Don't cache
    set beresp.uncacheable = true;
}

sub vcl_deliver {
    # Cache headers on assets
    if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|flv|js|css)$") {
        set resp.http.Cache-Control = "max-age=31536000";
        if (obj.hits > 0) {
            set resp.http.X-Cache = "HIT";
        } else {
            set resp.http.X-Cache = "MISS";
        }
        return (deliver);
    } else {
        # To debug if it's a hit or a miss
        set resp.http.Cache-Control = "no-store, no-cache, must-revalidate, post-check=0, pre-check=0";
    }

    unset resp.http.X-Session-UUID;

    unset resp.http.X-Made-Cache-Tags-1;
    unset resp.http.X-Made-Cache-Tags-2;
    unset resp.http.X-Made-Cache-Tags-3;

    unset resp.http.X-Made-Cache-Ttl;
    unset resp.http.url;

    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }

    return (deliver);
}
