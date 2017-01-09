<?php
namespace Unisharp\SwaggerTestCase\Routes;

use FastRoute\Dispatcher\GroupCountBased;

class Dispatcher extends GroupCountBased implements \FastRoute\Dispatcher
{
    protected function dispatchVariableRoute($routeData, $uri) {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches)) {
                continue;
            }

            list($handler, $varNames, $origin) = $data['routeMap'][count($matches)];
            $vars = [];
            $i = 0;
            foreach ($varNames as $varName) {
                $vars[$varName] = $matches[++$i];
            }
            return [self::FOUND, $handler, $vars, $origin];
        }

        return [self::NOT_FOUND];
    }
}
