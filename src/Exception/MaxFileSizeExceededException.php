<?php
namespace File\Exception;

use Cake\Core\Exception\Exception;

class MaxFileSizeExceededException extends Exception
{

    /**
     * {@inheritDoc}
     */
    public function __construct($message = 'The uploaded file exceeds the upload_max_filesize directive in php.ini', $code = 500, $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
