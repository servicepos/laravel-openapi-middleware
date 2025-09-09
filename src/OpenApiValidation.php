<?php

namespace C1st\Middleware;

use Closure;
use Exception;
use C1st\Middleware\OpenApiValidation\Exception\BeforeHandlerException;
use C1st\Middleware\OpenApiValidation\Exception\FileNotFoundException;
use C1st\Middleware\OpenApiValidation\Exception\InvalidOptionException;
use C1st\Middleware\OpenApiValidation\Exception\MissingFormatException;
use C1st\Middleware\OpenApiValidation\Exception\PathNotFoundException;
use C1st\Middleware\OpenApiValidation\Helpers\Json as JsonHelper;
use C1st\Middleware\OpenApiValidation\Helpers\Schema as SchemaHelper;
use C1st\Middleware\OpenApiValidation\Property;
use HKarlstrom\OpenApiReader\OpenApiReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Resolvers\FormatResolver;
use Opis\JsonSchema\Validator;

class OpenApiValidation
{

    private $openapi;
    private $options = [
        'additionalParameters'       => false,
        'beforeHandler'              => null,
        'errorHandler'               => null,
        'exampleResponse'            => false,
        'missingFormatException'     => true,
        'pathNotFoundException'      => true,
        'setDefaultParameters'       => false,
        'stripResponse'              => false,
        'stripResponseHeaders'       => false,
        'validateError'              => false,
        'validateRequest'            => true,
        'validateResponse'           => true,
        'validateResponseHeaders'    => false,
        'strictEmptyArrayValidation' => false,
        'validateSecurity'           => null,
    ];

    /** @var Validator */
    private $validator;
    /** @var FormatResolver */
    private $formatResolver;

    /**
     * @param string|array $schema
     * @param array        $options
     */
    public function __construct($schema, array $options = [])
    {
        if (is_string($schema) && ! file_exists($schema)) {
            throw new FileNotFoundException($schema);
        }
        $this->openapi = new OpenApiReader($schema);
        $allOptions    = array_keys($this->options);

        foreach ($options as $option => $value) {
            if (in_array($option, $allOptions)) {
                $this->options[$option] = $value;
            } else {
                throw new InvalidOptionException($option);
            }
        }

        $this->validator = new Validator();
        $this->validator->setMaxErrors(99);

        $this->formatResolver = $this->validator->parser()->getFormatResolver();

        // Password validator only checks that it's a string, as format=password only is a hint to the UI
        $this->formatResolver->register('string', 'password', new OpenApiValidation\Formats\PasswordValidator());
    }

    public function addFormat(string $type, string $name, \Opis\JsonSchema\Format $format)
    {
        $this->formatResolver->register($type, $name, $format);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $method         = mb_strtolower($request->getMethod());
        $pathParameters = [];
        $path           = $this->openapi->getPathFromUri($request->getRequestUri(), $method, $pathParameters);

        // If cors preflight request, dont do anything
        if ('options' == $method && $request->hasHeader('Access-Control-Request-Method')) {
            return $next($request);
        }

        if (null === $path && $this->options['pathNotFoundException']) {
            throw new PathNotFoundException($method, $request->getRequestUri());
        }
        if (null === $path) {
            return $next($request);
        }

        if (null !== $this->options['validateSecurity'] && $response = $this->validateSecurity($path, $method, $request)) {
            return $response;
        }
        if ($this->options['validateRequest']
            && $errors = $this->validateRequest($request, $path, $method, $pathParameters)) {
            if (is_callable($this->options['beforeHandler'])) {
                $beforeRequest = $this->options['beforeHandler']($request, $errors);
                if ($beforeRequest instanceof Request) {
                    $response = $next($beforeRequest);
                } else {
                    throw new BeforeHandlerException(gettype($beforeRequest));
                }
            } else {
                $response = $this->error(400, 'Request validation failed', $errors);
                if (! $this->options['validateError']) {
                    return $response;
                }
            }
        } else {
            $response = $next($request);
        }

        if (204 == $response->getStatusCode()) {
            return $response;
        }

        if ($this->options['exampleResponse']
            && '' === $response->getContent()) {
            $response = $this->setExampleResponse($request, $response, $path, $method);
        }

        if ($this->options['validateResponse']
            && $errors = $this->validateResponseBody($response, $path, $method)) {
            return $this->error(400, 'Response validation failed', $errors);
        }

        if ($this->options['validateResponseHeaders']
            && $errors = $this->validateResponseHeaders($response, $path, $method)) {
            return $this->error(400, 'Response validation failed', $errors);
        }

        return $response;
    }

    public function validateSecurity(string $path, string $method, Request $request)
    {
        $securityRequirements = $this->openapi->getOperationSecurity($path, $method);
        if (! count($securityRequirements)) {
            return null;
        }

        $callback = $this->options['validateSecurity'];

        // Check if request has provided any credentials
        foreach ($securityRequirements as $requirement) {
            foreach ($requirement as $name => $scopes) {
                $securitySceme = $this->openapi->getSecurityScheme($name);
                switch ($securitySceme->type) {
                    case 'http':
                        $authorizationHeader = $request->header('Authorization', false);
                        if ($authorizationHeader) {
                            // Remove basic or bearer
                            $token = str_replace(ucwords($securitySceme->scheme) . ' ', '', $authorizationHeader);
                            return $callback($request, 'http', $token, $scopes);
                        }
                        break;
                    case 'apiKey':
                        $token = false;
                        switch ($securitySceme->in) {
                            case 'query':
                                $token = $request->query($securitySceme->name, false);
                                break;
                            case 'header':
                                $token = $request->header($securitySceme->name, false);
                                break;
                            case 'cookie':
                                $token = $request->cookie($securitySceme->name, false);
                                break;
                        }
                        if ($token) {
                            return $callback($request, 'apiKey', $token, $scopes);
                        }
                        break;
                }
            }
        }
        return $callback($request, 'error', '', []);
    }

    public function validateResponseHeaders($response, string $path, string $method): array
    {
        $code           = $response->getStatusCode();
        $responseObject = $this->openapi->getOperationResponse($path, $method, $code);
        if (null === $responseObject) { // Not in file
            return [];
        }
        $headersSpecifications = $responseObject->getHeaders();
        $responseHeaders       = $response->headers->all();

        // https://swagger.io/specification/#responseObject
        // - If a response header is defined with the name "Content-Type", it SHALL be ignored.
        $normalizedHeaderNamesInSpecification = [];
        foreach ($headersSpecifications as $headerName => $header) {
            $normalizedHeaderNamesInSpecification[] = mb_strtolower($headerName);
        }

        // If stripResponseHeaders is true, remove additional headers
        if ($this->options['stripResponseHeaders']) {
            foreach ($responseHeaders as $headerName => $headerValue) {
                $normalizedHeaderName = mb_strtolower($headerName);
                if ('content-type' != $normalizedHeaderName && ! in_array($normalizedHeaderName, $normalizedHeaderNamesInSpecification)) {
                    $response->headers->remove($headerName);
                }
            }
        }

        // No specification for headers means any header allowed, skip the check
        if (empty($headersSpecifications)) {
            return [];
        }

        $normalizedResponseHeaders = [];
        foreach ($responseHeaders as $headerName => $values) {
            $normalizedHeaderName = mb_strtolower($headerName);
            // TODO Fix support if $values has many elements
            if ('content-type' != $normalizedHeaderName) {
                $normalizedResponseHeaders[$normalizedHeaderName] = is_array($values) ? $values[0] : $values;
            }
        }
        $properties = [];
        foreach ($headersSpecifications as $headerName => $header) {
            if (is_string($headerName)) {
                $properties[] = Property::fromHeader($headerName, $header, $normalizedResponseHeaders[mb_strtolower($headerName)] ?? null);
            }
        }
        return $this->validateProperties($properties);
    }

    public function validateResponseBody(&$response, string $path, string $method): array
    {
        $errors           = [];
        $responseBodyData = json_decode($response->getContent(), true);
        $code             = $response->getStatusCode();
        $mediaType        = $this->getMediaType($response);
        $responseObject   = $this->openapi->getOperationResponse($path, $method, $code);
        if (null === $responseObject) { // Not in file
            return [];
        }
        $responseSchema = $responseObject->getContent($mediaType)->schema ?? null;
        if (null === $responseSchema) {
            $mediaType      = $responseObject->getDefaultMediaType();
            $responseSchema = $responseObject->getContent($mediaType)->schema;
        }
        if ($mediaType !== 'application/json') { // not supposed to be a json response. can't validate reliably.
            return [];
        }
        if (null === $responseBodyData && $responseSchema) {
            return [['name' => 'responseBody', 'code' => 'error_required']];
        }
        if ($this->options['stripResponse']) {
            $responseSchema = JsonHelper::additionalProperties($responseSchema, false);
        }
        $responseBodyDataJson = json_encode($responseBodyData, JSON_PRESERVE_ZERO_FRACTION);
        if (is_string($responseBodyDataJson)) {
            $errors = $this->validateObject($responseSchema, $responseBodyDataJson);
        }
        if ($this->options['stripResponse']) {
            $notAdditionalOrNullErrors = [];
            foreach ($errors as $error) {
                if ('error_additional' == $error['code'] || 'error_additionalProperties' == $error['code']) {
                    if (null !== $error['name'] && isset($error['properties'])) {
                        // Build full path: error name + each property
                        $basePath = explode('.', $error['name']);
                        foreach ($error['properties'] as $prop) {
                            $fullPath         = array_merge($basePath, [$prop]);
                            $responseBodyData = JsonHelper::remove($responseBodyData, $fullPath);
                        }
                    } elseif (null !== $error['name']) {
                        $responseBodyData = JsonHelper::remove($responseBodyData, explode('.', $error['name']));
                    } elseif (isset($error['properties'])) {
                        // Handle case where error name is null but we have properties list
                        foreach ($error['properties'] as $prop) {
                            $responseBodyData = JsonHelper::remove($responseBodyData, [$prop]);
                        }
                    }
                } elseif ('error_type' == $error['code'] && 'null' == $error['type'] && null === $error['value']) {
                    $responseBodyData = JsonHelper::remove($responseBodyData, explode('.', $error['name']));
                } else {
                    $notAdditionalOrNullErrors[] = $error;
                }
            }
            $errors = $notAdditionalOrNullErrors;
            if ($response instanceof JsonResponse) {
                $response->setData($responseBodyData);
            } else {
                $response->setContent(json_encode($responseBodyData, JSON_PRESERVE_ZERO_FRACTION));
            }
        }
        return $errors;
    }

    public function validateRequest(Request &$request, string $path, string $method, array $pathValues): array
    {
        $errors          = [];
        $parameters      = $this->openapi->getOperationParameters($path, $method);
        $request         = $this->deserialize($request, $pathValues, $parameters);
        $values          = ['path' => $pathValues];
        $values['query'] = $request->query->all();

        if (! $this->options['additionalParameters']) {
            $errors = array_merge($errors, $this->checkAdditionalParamters($parameters, $values));
        }
        if ($this->options['setDefaultParameters']) {
            $request         = $this->setDefaultParamterValues($parameters, $request, $values);
            $values['query'] = $request->query->all();
        }

        $properties = [];
        foreach ($parameters as $p) {
            if ($p->in === 'header') {
                $value        = $request->header($p->name);
                $properties[] = Property::fromParameter($p, $value ?? null);
            } else {
                $properties[] = Property::fromParameter($p, $values[$p->in][$p->name] ?? null);
            }
        }

        $errors          = array_merge($errors, $this->validateProperties($properties));
        $requestBody     = $this->openapi->getOperationRequestBody($path, $method);
        $requestBodyData = $request->isJson() ? $request->json()->all() : $request->all();
        $mediaType       = $this->getMediaType($request);

        // Check if request body is truly empty (no content was provided)
        $hasRequestBody = !empty($request->getContent());
        if (!$hasRequestBody && $request->isJson()) {
            $requestBodyData = null;
        }

        if ($requestBody && $requestMediaType = $requestBody->getContent($mediaType)) {
            if (null === $requestBodyData && $requestBody->required) {
                $errors[] = ['name' => 'requestBody', 'code' => 'error_required'];
            } elseif (null !== $requestBodyData && $this->isJsonMediaType($mediaType)) {
                if (empty($requestBodyData)) {
                    // We don't know if the empty request body was an array [] or a object {}, both are decoded to [] by json_decode
                    $requestBodyData = $request->getContent();
                } else {
                    $requestBodyData = json_encode($requestBodyData, JSON_PRESERVE_ZERO_FRACTION);
                }
                $errors = array_merge($errors, $this->validateObject($requestMediaType->schema, $requestBodyData));
            } elseif ('multipart/form-data' === $mediaType
                || 'application/x-www-form-urlencoded' === $mediaType) {
                $errors = array_merge($errors, $this->validateFormData($requestMediaType->schema, $requestMediaType->encoding, $request));
            }
        }
        return $errors;
    }

    private function checkFormat(string $type, string $format)
    {
        if (null === $this->formatResolver->resolve($format, $type)) {
            try {
                $this->formatResolver->register($type, $format, new OpenApiValidation\Formats\RespectValidator($format));
            } catch (Exception $e) {
                if ($this->options['missingFormatException']) {
                    throw new MissingFormatException($type, $format);
                }
            }
        }
    }

    private function deserialize(Request $request, array &$pathValues, array $parameters): Request
    {
        foreach ($parameters as $parameter) {
            $schema = $parameter->schema;
            if (! in_array($schema->type, ['array', 'object'])) {
                continue;
            }
            $name = $parameter->name;
            if ('query' === $parameter->in) {
                $queryParams = $request->query->all();
                if (is_string($queryParams[$name] ?? null)) {
                    $queryParams[$name] = $this->styleValue(
                        $parameter->in,
                        $parameter->style,
                        $parameter->explode,
                        $queryParams[$name]
                    );
                    $request->query->replace($queryParams);
                }
            }
        }
        return $request;
    }

    private function styleValue(string $in, string $style, bool $explode, string $value)
    {
        switch ($in) {
            case 'query':
                switch ($style) {
                    case 'form':
                        return ! $explode ? explode(',', $value) : $value;
                    case 'spaceDelimited':
                        return explode(' ', $value);
                    case 'pipeDelimited':
                        return explode('|', $value);
                }
        }
        return $value;
    }

    private function validateProperties(array $properties): array
    {
        $errors = [];
        foreach ($properties as $property) {
            if (isset($property->schema->type, $property->schema->format)) {
                $type = $property->schema->type;
                $type = is_array($type) ? current($type) : $type;
                $this->checkFormat($type, $property->schema->format);
            }
        }

        foreach ($properties as $property) {
            if (null === $property->value) {
                if ($property->required) {
                    $err = [
                        'name' => $property->name,
                        'code' => 'error_required',
                        'in'   => $property->in,
                    ];
                    $errors[] = $err;
                }
                continue;
            }
            try {
                $value  = json_decode(json_encode($property->value, JSON_PRESERVE_ZERO_FRACTION));
                $result = $this->validator->validate($value, $property->schema);
            } catch (Exception $e) {
            }
            if (isset($result) && $result->hasError()) {
                $error = $this->parseErrors($result->error(), $property->name, $property->in, $property);

                foreach ($error as $parsedError) {
                    // As all query param values are strings type errors should be discarded
                    $discard = false;
                    if ('query' === $parsedError['in']
                        && 'error_type' === $parsedError['code']
                        && 'string' === $parsedError['type']) {
                        if ('integer' === $parsedError['expected']
                            && preg_match('/^[0-9]$/', $parsedError['value'])) {
                            $discard = true;
                        } elseif ('boolean' === $parsedError['expected']
                            && in_array(mb_strtolower($parsedError['value']), ['0', '1', 'true', 'false'])) {
                            $discard = true;
                        }
                    }
                    if (! $discard) {
                        $errors[] = $parsedError;
                    }
                }
            }
        }

        return $errors;
    }

    private function validateObject(array $schema, string $value): array
    {
        $errors = [];
        foreach (SchemaHelper::getFormats($schema) as $f) {
            $this->checkFormat($f['type'], $f['format']);
        }

        $schema = SchemaHelper::openApiToJsonSchema($schema);
        try {
            $value  = json_decode($value);
            $schema = json_decode(json_encode($schema, JSON_PRESERVE_ZERO_FRACTION));
            $result = $this->validator->validate($value, $schema, );
        } catch (Exception $e) {
            return [[
                'name'    => 'server',
                'code'    => 'error_server',
                'message' => $e->getMessage(),
            ]];
        }
        if ($result->hasError()) {
            return $this->parseErrors($result->error(), null, 'body');
        }
        return $errors;
    }

    private function validateFormData(array $schema, array $encoding, Request $request): array
    {
        $errors        = [];
        $formData      = $request->all();
        $properties    = [];
        $uploadedFiles = $request->allFiles();
        $schema        = SchemaHelper::openApiToJsonSchema($schema);
        foreach ($schema['properties'] as $name => $property) {
            if (isset($property['format']) && in_array($property['format'], ['binary', 'base64'])) {
                if (in_array($name, $schema['required'] ?? []) && ! isset($uploadedFiles[$name])) {
                    $errors[] = ['name' => $name, 'code' => 'error_required'];
                } elseif ($uploaded = $uploadedFiles[$name] ?? null) {
                    if (isset($encoding[$name]) && ! $encoding[$name]->hasContentType($uploaded->getClientMimeType())) {
                        $types    = $encoding[$name]->contentTypes;
                        $errors[] = [
                            'name'     => $name,
                            'code'     => 'error_content_type',
                            'expected' => 1 === count($types) ? $types[0] : $types,
                            'type'     => $uploaded->getClientMimeType(),
                        ];
                    }
                }
            } else {
                $properties[] = new Property(
                    $name,
                    'form-data',
                    in_array($name, $schema['required'] ?? []),
                    $property,
                    $formData[$name] ?? null
                );
            }
        }
        if (count($properties)) {
            $errors = array_merge($errors, $this->validateProperties($properties));
        }
        return $errors;
    }

    private function checkAdditionalParamters(array $parameters, array $values): array
    {
        $errors  = [];
        $defined = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];
        foreach ($parameters as $p) {
            $defined[$p->in][] = $p->name;
        }
        foreach ($values as $in => $map) {
            foreach ($map as $name => $value) {
                if (! in_array($name, $defined[$in])) {
                    $errors[] = [
                        'name' => $name,
                        'code' => 'error_additional',
                        'in'   => $in,
                    ];
                }
            }
        }
        return $errors;
    }

    private function setDefaultParamterValues(array $parameters, Request $request, array $values): Request
    {
        foreach ($parameters as $parameter) {
            if ('query' == $parameter->in && ! isset($values['query'][$parameter->name]) && isset($parameter->name, $parameter->schema->default)) {
                $values['query'][$parameter->name] = $parameter->schema->default;
            }
        }
        $request->query->replace($values['query']);
        return $request;
    }

    private function setExampleResponse(Request $request, $response, string $path, string $method)
    {
        if ($responseObject = $this->openapi->getOperationResponse($path, $method)) {
            $requestBodyData = $request->isJson() ? $request->json()->all() : $request->all();
            if (null === $mediaType = $this->getMediaType($request)) {
                $mediaType = $responseObject->getDefaultMediaType();
            }
            $exampleResponseBodyData = $responseObject->getContent($mediaType)->getExample() ?? [];
            if (null !== $requestBodyData && ! isset($exampleResponseBodyData[0])) {
                // If the request is a post or put, merge the request data to the example if the example is an object,
                // just to make the dummy data a bit more like in real life
                $exampleResponseBodyData = array_merge($exampleResponseBodyData, $requestBodyData);
            }

            $content    = json_encode($exampleResponseBodyData, JSON_PRESERVE_ZERO_FRACTION);
            $statusCode = is_numeric($responseObject->statusCode) ? intval($responseObject->statusCode) : 200;

            return new JsonResponse($exampleResponseBodyData, $statusCode, [
                'Content-Type' => $mediaType . ';charset=utf-8',
            ]);
        }
        return $response;
    }

    private function error(int $code, string $message, array $errors = [])
    {
        if (is_callable($this->options['errorHandler'])) {
            $response = $this->options['errorHandler']($code, $message, $errors);
            if ($response instanceof Response || $response instanceof JsonResponse) {
                return $response;
            }
        }

        $json = ['message' => $message];
        if (count($errors)) {
            $json['errors'] = $errors;
        }

        return new JsonResponse($json, $code);
    }

    private function parseErrors(ValidationError $error, $name = null, $in = null, ?Property $property = null): array
    {
        $errors = [];
        if ($error->subErrors()) {
            foreach ($error->subErrors() as $subError) {
                $errors = array_merge($errors, $this->parseErrors($subError, $name, $in));
            }
        } else {
            if ($error->data()->fullPath()) {
                $name = trim($name . '.' . implode('.', $error->data()->fullPath()), '.');
            }
            $err = [
                'name'  => $name,
                'code'  => 'error_' . $error->keyword(),
                'value' => $error->data()->value(),
            ];
            if ($in) {
                $err['in'] = $in;
            }
            foreach ($error->args() as $attr => $value) {
                $err[$attr] = $value;
            }
            if ('error_required' == $err['code']) {
                $err['name'] .= ($err['name'] && mb_strlen($err['name'])) ? '.' . array_shift($err['missing']) : array_shift($err['missing']);
                unset($err['missing'], $err['value']);
            }
            if ('error_enum' == $err['code'] && isset($property->schema->enum)) {
                $err['expected'] = $property->schema->enum;
            }
            if ('error_' . '$' . 'schema' == $err['code']) {
                // This is a quickfix as the opis/json-schema wont give any other error message
                // There should not be any other reason this error_$schema occurs
                $err['code'] = 'error_additional';
                unset($err['schema']);
            }
            if (isset($err['expected']) && ! isset($err['used']) && isset($err['type'])) {
                $err['used'] = $err['type'];
            }
            // As the request body is parsed as an array, empty object and empty array will both be []
            // Remove these errors
            if (! $this->options['strictEmptyArrayValidation']) {
                if ('error_type' == $err['code'] && empty($err['value']) && 'object' == $err['expected'] && 'array' == $err['type']) {
                    return [];
                }
            }
            $errors[] = $err;
        }
        return $errors;
    }

    private function getMediaType($message): ?string
    {
        if ($message instanceof Request) {
            $header = $message->header('Content-Type');
        } else {
            // Assume it's a Laravel Response
            $header = $message->headers->get('Content-Type');
        }

        if (! $header) {
            return null;
        }
        $contentTypeParts = preg_split('/\s*[;,]\s*/', $header);
        return mb_strtolower($contentTypeParts[0]);
    }

    private function isJsonMediaType(string $type): bool
    {
        // Allow JSON and JSON-formatted (eg: JSON-API) requests to be validated.
        return 'application/json' === $type || false !== mb_strpos($type, '+json');
    }
}
