# File plugin for CakePHP

## Requirements

It is developed for CakePHP 3.x.

If would like using in Cake 2.x check branch named `2.x`, but it is no longer supported.

## Installation

You can install plugin into your CakePHP application using [composer](http://getcomposer.org).

The recommended way to install composer packages is:

```
composer require kicaj/file
```

## TODOs

- [x] Add light Exceptions
- [x] Work with ImageMagick
- [ ] Work with Gmagick
- Support for image type:
  - [x] WebP
  - [ ] HEIF/HEIC (not now in GD)
- [ ] Add command to work with many files
- [ ] Add support to correct orientation by EXIF (https://stackoverflow.com/questions/7489742/php-read-exif-data-and-adjust-orientation)
