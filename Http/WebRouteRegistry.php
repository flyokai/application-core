<?php

namespace Flyokai\ApplicationCore\Http;

class WebRouteRegistry
{
    /**
     * @var array<int, array{method: string|string[], uri: string, controller: class-string}>
     */
    private array $routes = [];

    /**
     * Last-wins by (method-set, uri): re-registering the same method+URI replaces the prior entry,
     * preserving its position in declaration order.
     *
     * @param string|string[] $method
     * @param string $uri
     * @param class-string $controller
     */
    public function addRoute(string|array $method, string $uri, string $controller): self
    {
        $key = $this->routeKey($method, $uri);
        $entry = [
            'method' => $method,
            'uri' => $uri,
            'controller' => $controller,
        ];
        foreach ($this->routes as $i => $existing) {
            if ($this->routeKey($existing['method'], $existing['uri']) === $key) {
                $this->routes[$i] = $entry;
                return $this;
            }
        }
        $this->routes[] = $entry;
        return $this;
    }

    /**
     * @param string|string[] $method
     */
    private function routeKey(string|array $method, string $uri): string
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map('strtoupper', $methods);
        sort($methods);
        return implode(',', $methods).' '.$uri;
    }

    /**
     * @return array<int, array{method: string|string[], uri: string, controller: class-string}>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
