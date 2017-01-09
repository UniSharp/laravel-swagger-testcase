<?php

namespace Unisharp\SwaggerTestCase\Routes;

class Route extends \FastRoute\Route
{
    public $origin;

    public function __construct($httpMethod, $handler, $regex, array $variables, $origin = '')
    {
        parent::__construct($httpMethod, $handler, $regex, $variables);
        $this->origin = $origin;
    }
}
