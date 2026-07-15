# Server requirements for dobavki.club (not part of the shipped plugin)

## Object cache: memcached must be at least 512 MB

The site uses a persistent object cache (`wp-content/object-cache.php`, memcached
backend). With an external object cache active, ALL WordPress transients live only
in memcached - never in the database. If memcached is too small, transients are
evicted within minutes and everything they are supposed to cache re-runs on every
request: plugin/theme/core update checks against api.wordpress.org, Jetpack calls,
loopback requests, WooCommerce caches. The result is 2-6 s TTFB on every uncached
PHP request while the CPU sits idle.

This happened on 2026-07-16: memcached ran with `CACHESIZE="64"` (64 MB), was 88%
full with 108k evictions, and every wp-admin page took seconds. Raising it fixed it.

- Config: `/etc/sysconfig/memcached`, `CACHESIZE="512"` (server has ~8 GB RAM).
- Apply with `systemctl restart memcached` (empties the cache for all sites on the
  box - they cold-start for a few minutes, prefer night time).
- Health check: `echo stats | nc 127.0.0.1 11211 | grep -E "evictions|bytes|limit"`.
  `evictions` growing fast, or `bytes` near `limit_maxbytes`, means it is too small.
- Note: the `alloptions` blob alone is ~850 KB uncompressed (~180 KB stored), and
  memcached 1.4.15 has a 1 MB item-size limit - trimming autoloaded options in
  `wp_options` is a worthwhile future optimization.
