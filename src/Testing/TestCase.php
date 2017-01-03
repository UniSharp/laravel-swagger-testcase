<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class TestCase extends Laravel\Lumen\Testing\TestCase
{
    protected $swagger = [];
    protected $doc_path = 'doc/swagger.json';
    /**
     * Creates the application.
     *
     * @return \Laravel\Lumen\Application
     */
    public function createApplication()
    {
        return require __DIR__.'/../bootstrap/app.php';
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
        parent::call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null);

        $request = Request::create(
            $this->prepareUrlForRequest($uri),
            $method,
            $parameters,
            $cookies,
            $files,
            $server,
            $content
        );
        $this->parseSwagger($request);
    }

    public function parseSwagger(Request $request)
    {
        $pathItemObject = [
            strtolower($request->getMethod()) => [
                'parameters' => $this->parseParameters($request)
            ]
        ];

        $pathObject = [
            $request->getPathInfo() => $pathItemObject
        ];

        if (!isset($this->swagger['paths'])) {
            $this->swagger['paths'] = [];
        }

        $this->swagger['paths'] += $pathObject;

        return $this->swagger;
    }

    public function parseParameters(Request $request)
    {
        $parameterObject = [];
        collect($request->query())->map(function ($value, $key) use (&$parameterObject) {
            $parameterObject[] = [
                'name'        => $key,
                'description' => '',
                'in'          => 'query',
                'required'    => true,
            ];
            $parameterObject += $this->getParameterType($value);
        });

        if ($request->getRequestFormat() == 'form') {
            collect($request->all())->map(function ($value, $key) use ($request, &$parameterObject) {
                $parameterObject[] = [
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
            });
        }
        if (!empty($request->getContent())) {
            $parameterObject = [
                'description' => '',
                'in'          => 'body',
                'required'    => true,
                'schema'      => $this->parseSchemaObject(json_decode($request->getContent()))
            ];
        }
        return $parameterObject;
    }

    public function parseSchemaObject($content)
    {
        $schema = [];
        $schema += $this->getParameterType($content);
        if (is_object($content)) {
            collect((array) $content)->map(function ($value, $key) use (&$schema) {
                if (!isset($schema['property'])) {
                    $schema['properties'] = [];
                }

                $schema[$key] = $this->parseSchemaObject(json_decode(json_encode($value)));
            });
        }
        return $schema;
    }

    public function getParameterType($value)
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

    public function tearDown()
    {
        parent::tearDown();

        if (!File::exists(pathinfo($this->doc_path, PATHINFO_DIRNAME))) {
            File::makeDirectory(pathinfo($this->doc_path, PATHINFO_DIRNAME));
        }

        File::put(
            base_path($this->doc_path),
            json_encode($this->swagger, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
        );
    }

}
