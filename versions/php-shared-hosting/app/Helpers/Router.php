<?php

declare(strict_types=1);

namespace App\Helpers;

/** Minimal route table: method + path pattern -> [ControllerClass, 'method']. No framework dependency (shared-hosting constraint). */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable|array, paramNames:array<int,string>}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'paramNames' => $paramNames,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['paramNames'], $matches);
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $methodName] = $handler;
                    $instance = new $class();
                    $instance->$methodName($params);
                } else {
                    $handler($params);
                }
                return;
            }
        }

        http_response_code(404);
        if (is_file(APP_ROOT . '/app/Views/errors/404.php')) {
            require APP_ROOT . '/app/Views/errors/404.php';
        } else {
            echo '404 Not Found';
        }
    }
}
