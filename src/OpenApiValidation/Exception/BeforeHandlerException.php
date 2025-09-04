<?php


namespace C1st\Middleware\OpenApiValidation\Exception;

use Throwable;
use UnexpectedValueException;

class BeforeHandlerException extends UnexpectedValueException
{
    /** @var string */
    protected $type;

    /**
     * BeforeHandlerException constructor.
     *
     * @param string         $type
     * @param Throwable|null $previous
     */
    public function __construct(string $type, Throwable $previous = null)
    {
        $this->type = $type;
        parent::__construct(sprintf("Return value of 'beforeHandler' should be 'ServerRequestInterface', not '%s'", $type), 0, $previous);
    }

    public function type() : string
    {
        return $this->type;
    }
}
