<?php


namespace C1st\Middleware\OpenApiValidation\Exception;

use RuntimeException;
use Throwable;

class MissingFormatException extends RuntimeException
{
    /** @var string */
    protected $type;

    /** @var string */
    protected $format;

    /**
     * MissingFormatException constructor.
     *
     * @param string         $type
     * @param string         $format
     * @param Throwable|null $previous
     */
    public function __construct(string $type, string $format, Throwable $previous = null)
    {
        $this->type   = $type;
        $this->format = $format;
        parent::__construct(sprintf('Missing validator for type=%s, format=%s', $type, $format), 0, $previous);
    }

    public function type() : string
    {
        return $this->type;
    }

    public function format() : string
    {
        return $this->format;
    }
}
