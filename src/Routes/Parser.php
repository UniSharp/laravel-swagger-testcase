<?php
namespace Unisharp\SwaggerTestCase\Routes;

use FastRoute\RouteParser;

class Parser extends RouteParser\Std implements RouteParser
{

    public function parse($route)
    {
        $datas = parent::parse($route);
        foreach ($datas as $i => $data) {
            $datas[$i][] = $route;
        }

        return $datas;
    }
}
