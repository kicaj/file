<?php
namespace File\Exception;

use Cake\Core\Exception\Exception;

class ThumbsException extends Exception
{
    /**
     * @inheritdoc
     */
    public function __construct($message, $code = 500, $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}