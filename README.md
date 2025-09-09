> [!IMPORTANT]
> This is a heavily modified fork of [hkarlstrom/openapi-validation-middleware](https://github.com/hkarlstrom/openapi-validation-middleware) where we reworte it to be a laravel only middleware, such that it can be used in a laravel project without the need for any psr-15 bridge libraries. The tests have been rewritten with Pest.

# OpenAPI Validation Middleware for Laravel

Laravel [OpenAPI](https://www.openapis.org/) Validation Middleware

The middleware parses an OpenAPI definition document (openapi.json or openapi.yaml) and validates:
* Request parameters (path, query)
* Request body
* Response body

This middleware is specifically designed for [Laravel](https://laravel.com/) applications and integrates seamlessly with Laravel's request/response system.

All testing has been done using Laravel Framework. The tests are done with a openapi.json file that is valid according to [Swagger/OpenAPI CLI](https://www.npmjs.com/package/swagger-cli)


## Installation

It's recommended that you use [Composer](https://getcomposer.org/download) to install.
```shell
composer require servicepos/laravel-openapi-middleware
```

Use [Swagger/OpenAPI CLI](https://www.npmjs.com/package/swagger-cli) to validate openapi.json/openapi.yaml file, as the middleware assumes it to be valid.


## Usage

### Global Middleware Registration

Register the middleware globally in `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ... other middleware
    \C1st\Middleware\OpenApiValidation::class,
];
```

### Route-Specific Middleware

Register as a route middleware in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... other middleware
    'openapi' => \C1st\Middleware\OpenApiValidation::class,
];
```

Then use it on specific routes:

```php
Route::middleware('openapi')->group(function () {
    Route::get('/api/users', [UserController::class, 'index']);
    Route::post('/api/users', [UserController::class, 'store']);
});
```

### Basic Usage with Configuration

```php
// In a service provider or controller
$middleware = new \C1st\Middleware\OpenApiValidation('/path/to/openapi.json', [
    'validateRequest' => true,
    'validateResponse' => true,
]);
```


| type                       | format    | default | description |
| -------------------------- | --------- | ------- | --- |
| additionalParameters       | bool      | false   | Allow additional parameters in query |
| beforeHandler              | callable  | null    | Instructions [below](README.md#beforehandler) |
| errorHandler               | callable  | null    | Instructions [below](README.md#errorhandler) |
| exampleResponse            | bool      | false   | Return example response from openapi.json/openapi.yaml if route implementation is empty |
| missingFormatException     | bool      | true    | Throw an exception if a format validator is missing |
| pathNotFoundException      | bool      | true    | Throw an exception if the path is not found in openapi.json/openapi.yaml |
| setDefaultParameters       | bool      | false   | Set the default parameter values for missing parameters and alter the request object |
| strictEmptyArrayValidation | bool      | false   | Consider empty array when object is expected as validation error |
| stripResponse              | bool      | false   | Strip additional attributes from response to prevent response validation error |
| stripResponseHeaders       | bool      | false   | Strip additional headers from response to prevent response validation error |
| validateError              | bool      | false   | Should the error response be validated |
| validateRequest            | bool      | true    | Should the request be validated |
| validateResponse           | bool      | true    | Should the response's body be validated |
| validateResponseHeaders    | bool      | false   | Should the response's headers be validated |
| validateSecurity           | callable  | null    | Instructions [below](README.md#validateSecurity) |


#### beforeHandler
If defined, the function is called when the request validation fails before the next incoming middleware is called. You can use this to alter the request before passing it to the next incoming middleware in the stack. If it returns anything else than \Illuminate\Http\Request an exception will be thrown. The `array $errors` is an array containing all the validation errors.
```php
$options = [
    'beforeHandler' => function (\Illuminate\Http\Request $request, array $errors) : \Illuminate\Http\Request {
        // Alter request
        return $request;
    }
];
```

#### errorHandler
If defined, the function is called instead of the default error handler. If it returns anything else than \Illuminate\Http\Response or \Illuminate\Http\JsonResponse it will fallback to the default error handler.
```php
$options = [
    'errorHandler' => function (int $code, string $message, array $errors) : \Illuminate\Http\JsonResponse {
        // Create custom error response
        return response()->json([
            'error' => $message,
            'details' => $errors
        ], $code);
    }
];
```


## Formats

There are two ways to validate formats not defined in the [OAS](https://swagger.io/specification/#dataTypes) specification. You can implement a custom format validator and add it to the middleware, or use the build in support for the [Respect Validation](http://respect.github.io/Validation/) libray.

#### Custom validator

```php
class MyOwnFormat implements Opis\JsonSchema\Format {
    public function validate($data) : bool
    {
        // Validate data
        // $isValid = ...
        return $isValid;
    }
}

$mw = new C1st\Middleware\OpenApiValidation('/path/to/openapi.json');
$mw->addFormat('string','my-own-format',new MyOwnFormat());
$app->add($mw);
```

#### Respect Validation

You can use [all the validators](http://respect.github.io/Validation/docs/validators.html) just by setting the `format` property in your openapi.json/openapi.yaml file.
```json
"schema":{
    "type" : "string",
    "format": "country-code"
}
```
The `country-code` value will resolve to the `v::countryCode()` validator.

You can also pass arguments to the validator defined in the format attribute:

```json
"schema": {
    "type": "string",
    "format":"ends-with('@gmail.com')"
}
```
or
```json
"schema": {
    "type": "integer",
    "format":"between(10, 20)"
}
```

## License

The OpenAPI Validation Middleware is licensed under the MIT license. See [License File](LICENSE) for more information.
