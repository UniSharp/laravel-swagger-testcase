<?php
namespace Unisharp\SwaggerTestCase\Testing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Unisharp\SwaggerTestCase\Routes\DataGenerater;
use Unisharp\SwaggerTestCase\Routes\Dispatcher;
use Unisharp\SwaggerTestCase\Routes\Parser;

class TestCase extends \Laravel\Lumen\Testing\TestCase
{
    protected $swagger = [];
    protected $doc_path = 'doc/swagger.json';
    protected $request = null;
    protected $expectedResponseCode = 200;
    protected $expectedResponse = [];
    protected $parameterDescriptions = [];
    protected $responseDescription = '';

    public function createApplication()
    {
        return require base_path('/bootstrap/app.php');
    }

    public function setUp()
    {
        parent::setUp();
        if (File::exists($this->doc_path)) {
            $this->swagger = json_decode(File::get(base_path($this->doc_path)), true);
        }

    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $request = Request::create(
            $this->prepareUrlForRequest($uri),
            $method,
            $parameters,
            $cookies,
            $files,
            $server,
            $content
        );

        $this->request = $request;
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function assertResponseOk()
    {
        $this->expectedResponseCode = 200;
        parent::assertResponseOk();
    }

    public function assertResponseStatus($code)
    {
        $this->expectedResponseCode = $code;
    }

    public function seeJsonEquals(array $data)
    {
        parent::seeJsonEquals($data);
        if (empty($this->expectedResponse)) {
            $this->expectedResponse = $data;
        } else {
            array_merge_recursive($this->expectedResponse, $data);
        }

        return $this;
    }

    public function describeParameter($key, $description, $in = 'body')
    {
        $this->parameterDescriptions[] = [
            'key'         => $key,
            'description' => $description,
            'in'          => $in
        ];

        return $this;
    }

    public function describePathParameter($key, $description)
    {
        return $this->describeParameter($key, $description, 'path');
    }

    public function describeFormParameter($key, $description)
    {
        return $this->describeParameter($key, $description, 'formData');
    }

    public function describeQueryParameter($key, $description)
    {
        return $this->describeParameter($key, $description, 'query');
    }

    public function describeResponse($description)
    {
        $this->responseDescription = $description;
        return $this;
    }

    public function tearDown()
    {
        $this->parseSwagger($this->request);
        parent::tearDown();

        if (!File::exists(pathinfo($this->doc_path, PATHINFO_DIRNAME))) {
            File::makeDirectory(pathinfo($this->doc_path, PATHINFO_DIRNAME));
        }

        File::put(
            base_path($this->doc_path),
            json_encode($this->swagger, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
        );
    }

    protected function simpleDispatcher($routeDefinitionCallback, $options = [])
    {
        $options += [
            'routeParser' => Parser::class,
            'dataGenerator' => DataGenerater::class,
            'dispatcher' => Dispatcher::class,
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];

        $routeCollector = new $options['routeCollector'](
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        return new $options['dispatcher']($routeCollector->getData());
    }

    protected function getRouterDispatcher()
    {
        $app = $this->app;
        return $this->simpleDispatcher(function ($r) use ($app) {
            foreach ($app->getRoutes() as $route) {
                $r->addRoute($route['method'], $route['uri'], $route['action']);
            }
        });
    }

    protected function parseSwagger(Request $request)
    {

        $method = strtolower($request->getMethod());
        $pathItemObject = [
            $method => [
                'parameters' => $this->parseParameterObjects($request),
            ]
        ];

        if (!isset($pathItemObject[$method]['responses'])) {
            $pathItemObject[$method]['responses'] = [];
        }

        $pathItemObject[$method]['responses'] += $this->parseResponseObjects($this->expectedResponse);

        $pathObject = [
            $this->getOriginalRoutePath($request) => $pathItemObject
        ];

        if (!isset($this->swagger['paths'])) {
            $this->swagger['paths'] = [];
        }


        $this->swagger['swagger']  = config('swagger.swagger', '2.0');
        $this->swagger['basePath'] = config('swagger.basePath', '/api/v1');
        $this->swagger['host']     = config('swagger.host', 'localhost');
        $this->swagger['info']     = config('swagger.info', [
            'title'       => 'Title',
            'description' => 'Description',
            'version'     => '0.1'
        ]);
        $this->swagger['paths']    = array_merge_recursive($this->swagger['paths'], $pathObject);

        return $this->swagger;
    }

    protected function getOriginalRoutePath(Request $request)
    {
        // /api/v1/entity/{variable:regex} -> /entity/{variable}
        $result = $this->getRouterDispatcher()->dispatch($request->getMethod(), $request->getPathInfo());
        return isset($result[3]) ?
            str_replace(config('swagger.basePath', '/api/v1'), '', preg_replace("/({.+?)(:.+?)(})/", "$1$3", $result[3])) :
            $request->getPathInfo();
    }

    protected function getPathParameters(Request $request)
    {
        $result = $this->getRouterDispatcher()->dispatch($request->getMethod(), $request->getPathInfo());
        return isset($result[2]) ? collect($result[2]) : collect() ;
    }

    protected function getParameterDescription($key, $in)
    {
        foreach ($this->parameterDescriptions as $description) {
            if ($description['key'] == $key && $description['in'] == $in) {
                return $description['description'];
            }
        }

        return '';
    }

    protected function parseParameterObjects(Request $request)
    {
        $parameterObjects = [];
        $parameters = [
            'query'     => collect($request->query()),
            'path'      => collect($this->getPathParameters($request)),
            'body'      => $request->getContent(),
            'formData'  => str_contains($request->getContentType(), ['/form']) ? collect($request->all()) : collect()
        ];

        collect($parameters)->map(function ($parameters, $in) use ($request, &$parameterObjects) {
            if ($in == 'body') {
                $parameterObject = [
                    'name'      => 'body',
                    'description' => '',
                    'in'          => $in,
                    'required'    => true,
                    'schema'      => $this->parseSchemaObject($parameters)
                ];

                $parameterObjects[] = $parameterObject;
                return;
            }

            $parameters->map(function ($parameter, $name) use ($request, $in, &$parameterObjects) {
                $parameterObject = [
                    'name'        => $name,
                    'description' => $this->getParameterDescription($name, $in),
                    'in'          => $in,
                    'required'    => true,
                ];


                $parameterObject += $this->getParameterType($parameter);
                $parameterObjects[] = $parameterObject;
            });
        });

        return $parameterObjects;
    }

    protected function parseSchemaObject($content)
    {
        $schema = [];
        $schema += $this->getParameterType($content);
        if (is_object($content)) {
            collect((array) $content)->map(function ($value, $key) use (&$schema) {
                if (!isset($schema['properties'])) {
                    $schema['properties'] = [];
                }

                $schema['properties'][$key] = $this->parseSchemaObject(json_decode(json_encode($value)));
            });
        }

        return $schema;
    }

    protected function parseResponseObjects($response)
    {
        return [$this->expectedResponseCode => $this->parseResponseObject($response)];
    }

    protected function parseResponseObject($response)
    {
        return [
            'description' => $this->responseDescription,
            'schema'      => $this->parseSchemaObject(json_decode(json_encode($response))),
            'examples'    => [
                'application/json' => $response
            ]
        ];
    }

    protected function getParameterType($value)
    {
        $data_type = [
            'type'   => '',
            'format' => ''
        ];

        switch ($value) {
            case ctype_digit($value):
                $data_type['type'] = 'integer';
                if (is_long($value)) {
                    $data_type['format'] = 'int64';
                } else {
                    $data_type['format'] = 'int32';
                }
                break;

            case is_numeric($value):
                $data_type['type'] = 'number';
                if (is_double($value)) {
                    $data_type['format'] = 'double';
                } else {
                    $data_type['format'] = 'float';
                }
                break;

            case is_bool($value):
                $data_type['type']   = 'boolean';
                $data_type['format'] = 'boolean';
                break;

            case is_object($value):
                $data_type['type']   = 'object';
                unset($data_type['format']);
                break;

            case is_array($value):
                $data_type['type']   = 'array';
                unset($data_type['format']);
                break;
            case is_string($value):
            default:
                $data_type['type']   = 'string';
                if (strtotime($value)) {
                    $data_type['format'] = 'date';
                } else {
                    $data_type['format'] = 'string';
                }
                break;
        }

        return $data_type;
    }
}
