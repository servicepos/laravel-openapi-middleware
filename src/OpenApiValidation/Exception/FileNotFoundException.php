<?php


namespace C1st\Middleware\OpenApiValidation\Exception;

use RuntimeException;
use Throwable;

class FileNotFoundException extends RuntimeException
{
    /** @var string */
    protected $filename;

    /**
     * FileNotFoundException constructor.
     *
     * @param string         $filename
     * @param Throwable|null $previous
     */
    public function __construct(string $filename, Throwable $previous = null)
    {
        $this->filename = $filename;
        parent::__construct(sprintf("The file '%s' was not found", $filename), 0, $previous);
    }

    public function filename() : string
    {
        return $this->filename;
    }
}
