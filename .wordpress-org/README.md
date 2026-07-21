# WordPress.org page assets

Images shown on <https://wordpress.org/plugins/ordelist> — **not** bundled in the
plugin zip. `bin/svn-deploy.sh` copies everything here (except this README) into
the SVN `/assets` directory on release.

Add PNGs with these exact names (see
<https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/>):

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | plugin icon |
| `icon-256x256.png` | 256×256 | retina icon |
| `banner-772x250.png` | 772×250 | header banner |
| `banner-1544x500.png` | 1544×500 | retina banner |
| `screenshot-1.png` | any | matches "1." in readme.txt `== Screenshots ==` |
| `screenshot-2.png` … | any | further screenshots, in order |

Screenshot numbers map to the captions already listed under `== Screenshots ==`
in `readme.txt` (currently four). Assets are optional for the plugin to work, but
the public page looks unfinished without at least an icon and banner.
