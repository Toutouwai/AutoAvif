# Auto AVIF

Automatically generates [AVIF](https://en.wikipedia.org/wiki/AVIF) files when image variations are created. The AVIF image format can provide better compression efficiency than JPG or WebP formats.

Requires ProcessWire v3.0.236 or newer. 

In order to generate AVIF files your environment must have a version of GD or Imagick that supports the AVIF format. If you are using ImageSizerEngineGD (the ProcessWire default) then this means you need PHP 8.1 or newer. If you want to use Imagick to generate AVIF files then you must have the core ImageSizerEngineIMagick module installed. The module attempts to detect if your environment supports AVIF and warns you on the module config screen if it finds a problem.

## Delayed Image Variations

Generating AVIF files is *very slow* - much slower than creating an equivalent JPG or WebP file. If you want to use this module it's highly recommended that you also install the [Delayed Image Variations](https://processwire.com/modules/delayed-image-variations/) module so that image variations are created one by one rather than all at once before a page renders. Otherwise it's likely that pages with more than a few images will timeout before the AVIF files can be generated.

## Configuration

On the module configuration screen are settings for "Quality (1 – 100)" and "Speed (0 – 9)". These are parameters for the underlying GD and Imagick AVIF generation methods.

There is also an option to create AVIF files for existing image variations instead of only new image variations. If you enable this option then all image variations on your site will be recreated the next time they are requested.

## Usage

Just install the module, choose the configuration settings you want, and make the changes to the `.htaccess` file in the site root described in the next section.

### How the AVIF files are served

The module doesn't have all the features that the ProcessWire core provides for WebP files. It's much simpler and uses `.htaccess` to serve an AVIF file instead of the original variation file when the visitor's browser supports AVIF and an AVIF file named the same as the variation exists. This may not be compatible with the various approaches the core takes to serving WebP files so you'll want to choose to serve either AVIF files via this module or WebP files via the core but not both.

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

### Opting out of AVIF generation for specific images

If you want to prevent an AVIF file being generated and served for a particular image you can hook `AutoAvif::allowAvif` and set the event return to false. AutoAVIF generates an AVIF file when an image variation is being created so the hookable method receives some arguments relating to the resizing of the requested variation.

Example:

```php
$wire->addHookAfter('AutoAvif::allowAvif', function(HookEvent $event) {
    $pageimage = $event->arguments(0); // The Pageimage that is being resized
    $width = $event->arguments(1); // The requested width of the variation 
    $height = $event->arguments(2); // The requested height of the variation 
    $options = $event->arguments(3); // The array of ImageSizer options supplied
    
    // You can check things like $pageimage->field, $pageimage->page and $pageimage->ext here...
    
    // Don't create an AVIF file if the file extension is PNG
    if($pageimage->ext === 'png') $event->return = false;
});
```

### Deleting an AVIF file

If you delete a variation via the "Variations > Delete Checked" option for an image in an Images field then any corresponding AVIF file is also deleted.

And if you delete an image then any AVIF files for that image are also deleted.
