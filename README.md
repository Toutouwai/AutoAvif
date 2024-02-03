# Auto AVIF

Automatically creates AVIF files when image variations are created.

## .htaccess

Two additions to the `.htaccess` file in the site root are needed.

1\. Immediately after the `RewriteEngine On` line:

```
# AutoAvif
# Check if browser supports AVIF images
RewriteCond %{HTTP_ACCEPT} image/avif
# Check if AVIF replacement image exists
RewriteCond %{DOCUMENT_ROOT}/$1.avif -f
# Serve AVIF image instead
RewriteRule (.+)\.(jpe?g|png|gif)$ $1.avif [T=image/avif,E=REQUEST_image,L]
```

2\. After the last line:

```
# AutoAvif
<IfModule mod_headers.c>
  Header append Vary Accept env=REQUEST_image
</IfModule>
<IfModule mod_mime.c>
  AddType image/avif .avif
</IfModule>
```
