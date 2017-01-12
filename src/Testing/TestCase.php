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
        parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $this->parseSwagger($request);
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
    }

    public function tearDown()
    {
        $this->parseResponseObjects();
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

        /** @var RouteCollector $routeCollector */
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

        $pathItemObject = [
            strtolower($request->getMethod()) => [
                'parameters' => $this->parseParameterObjects($request)
            ]
        ];

        $pathObject = [
            $this->getOriginalRoutePath($request) => $pathItemObject
        ];

        if (!isset($this->swagger['paths'])) {
            $this->swagger['paths'] = [];
        }

        $this->swagger['paths'] += $pathObject;

        return $this->swagger;
    }

    protected function getOriginalRoutePath(Request $request)
    {
        $result = $this->getRouterDispatcher()->dispatch($request->getMethod(), $request->getPathInfo());
        return isset($result[3]) ? $result[3] : $request->getPathInfo();
    }

    protected function getPathParameters(Request $request)
    {
        $result = $this->getRouterDispatcher()->dispatch($request->getMethod(), $request->getPathInfo());
        return isset($result[2]) ? collect($result[2]) : collect() ;
    }

    protected function parseParameterObjects(Request $request)
    {
        $parameterObjects = [];
        collect($request->query())->map(function ($value, $key) use (&$parameterObjects) {
            $parameterObject = [
                'name'        => $key,
                'description' => '',
                'in'          => 'query',
                'required'    => true,
            ];

            $parameterObject += $this->getParameterType($value);
            $parameterObjects[] = $parameterObject;
        });

        if ($request->getRequestFormat() == 'form') {
            collect($request->all())->map(function ($value, $key) use ($request, &$parameterObjects) {
                $parameterObject = [
                    'name' => $key,
                    'description' => '',
                    'in' => 'formData',
                    'required' => true,
                ];
                if ($request->isJson()) {
                    $parameterObject['schema'] = $this->getParameterType($value);
                } elseif (str_contains($request->getContentType(), ['/form'])) {
                    $parameterObject += $this->getParameterType($value);
                }

                $parameterObjects[] = $parameterObject;
            });
        }

        $this->getPathParameters($request)->each(function ($value, $key) use ($request, &$parameterObjects) {
            $parameterObject = [
                'name' => $key,
                'description' => '',
                'in'          => 'path',
                'required'    => true,
            ];

            $parameterObject += $this->getParameterType($value);
            $parameterObjects[] = $parameterObject;
        });

        if (!empty($request->getContent())) {
            $parameterObjects[] = [
                'description' => '',
                'in'          => 'body',
                'required'    => true,
                'schema'      => $this->parseSchemaObject(json_decode($request->getContent()))
            ];
        }

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

    protected function parseResponseObjects()
    {
        $responseSchema = $this->parseSchemaObject(json_decode(json_encode($this->expectedResponse)));
        if (!isset($this->swagger['paths'][$this->getOriginalRoutePath($this->request)]['responses'])) {
            $this->swagger['paths'][$this->getOriginalRoutePath($this->request)]['responses'] = [];
        }

        $this->swagger['paths'][$this->getOriginalRoutePath($this->request)]['responses'][$this->expectedResponseCode]['schema']
            = $responseSchema;


        $this->swagger['paths'][$this->getOriginalRoutePath($this->request)]['responses'][$this->expectedResponseCode]['examples']
            = $this->expectedResponse;
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
