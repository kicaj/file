<?php
namespace File\View\Helper;

use Cake\View\Helper;

class FileHelper extends Helper
{

    /**
     * {@inheritDoc}
     */
    public $helpers = [
        'Html',
    ];

    /**
     * Thumb image
     *
     * @param string|array $path Path to the image file, relative to the app/webroot/img/ direct
     * @param array $options Array of HTML attributes. See above for special options.
     * @param string $thumb Name of thumb
     * @return string completed img tag
     * @see https://book.cakephp.org/3.0/en/views/helpers/html.html#linking-to-images
     */
    public function thumb($path, $options = [], $thumb = 'default')
    {
        if ($thumb !== 'default') {
            $path = preg_replace('/default/', $thumb, $path);
        }

        if (!is_array($options)) {
            $options = [];
        }

        return $this->Html->image($path, $options);
    }
}
