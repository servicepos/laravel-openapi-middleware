<?php


namespace C1st\Middleware\OpenApiValidation\Exception;

use InvalidArgumentException;
use Throwable;

class InvalidOptionException extends InvalidArgumentException
{
    /** @var string */
    protected $option;

    /**
     * InvalidOptionException constructor.
     *
     * @param string         $option
     * @param Throwable|null $previous
     */
    public function __construct(string $option, Throwable $previous = null)
    {
        $this->option = $option;
        parent::__construct(sprintf("The option '%s' is invalid", $option), 0, $previous);
    }

    public function option() : string
    {
        return $this->option;
    }
}
