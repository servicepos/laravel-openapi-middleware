<?php


namespace C1st\Middleware\OpenApiValidation\Exception;

use InvalidArgumentException;
use Throwable;

class PathNotFoundException extends InvalidArgumentException
{
    /** @var string */
    protected $method;

    /** @var string */
    protected $path;

    /**
     * PathNotFoundException constructor.
     *
     * @param string         $method
     * @param string         $path
     * @param Throwable|null $previous
     */
    public function __construct(string $method, string $path, Throwable $previous = null)
    {
        $this->method = mb_strtoupper($method);
        $this->path   = $path;
        parent::__construct(sprintf('%s %s not defined in OpenAPI document', $this->method, $path), 0, $previous);
    }

    public function method() : string
    {
        return $this->method;
    }

    public function path() : string
    {
        return $this->path;
    }
}
