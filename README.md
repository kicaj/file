# CakePHP plugin for upload files

[![Build Status](https://scrutinizer-ci.com/g/slicesofcake/file/badges/build.png?b=master)](https://scrutinizer-ci.com/g/slicesofcake/file/build-status/master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/slicesofcake/file/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/slicesofcake/file/?branch=master)
[![LICENSE](https://img.shields.io/github/license/slicesofcake/file.svg)](https://github.com/slicesofcake/file/blob/master/LICENSE)
[![Releases](https://img.shields.io/github/release/slicesofcake/file.svg)](https://github.com/slicesofcake/file/releases)

Upload files with processing images using GD or ImageMagick library.

## Requirements

It is developed for CakePHP 4.x.

## Installation

You can install plugin into your CakePHP application using [composer](http://getcomposer.org).

The recommended way to install composer packages is:

```
composer require slicesofcake/file
```

## Setup

Add file type input in your view:

```
echo $this->Form->control('logo', [
    'type' => 'file',
]);
```

You should also add `'type' => 'file'` in your creating form method.

Note: If you want use multiple file input (from HTML5), just replace name of input field from `logo` to `logo[]` and add to options attribute `multiple`. 

Next, load behavior in your table on `initialize` method, like below:

```
$this->addBehavior('SlicesCake/File.File', [
    'logo',
]);
```
Note: Field should be accessible in Entity class.

## TODOs

- [x] Add light Exceptions
- [x] Work with ImageMagick
- [ ] Work with Gmagick
- [x] Add support to WEBP image type
- [ ] Add support to own method to generate names
- [x] Add support to work with many files
- [ ] Add command to work with files
- [ ] Add support to EXIF
- [ ] Add support to correct orientation by EXIF (https://stackoverflow.com/questions/7489742/php-read-exif-data-and-adjust-orientation)
