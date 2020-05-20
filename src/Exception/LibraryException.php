<?php
namespace SlicesCake\File\Exception;

use Cake\Core\Exception\Exception;

class LibraryException extends Exception
{
    
    /**
     * {@inheritDoc}
     */
    public function __construct($message, $code = 500, $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
