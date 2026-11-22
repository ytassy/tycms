<?php

namespace YTassy\Tycms\Routing;

use ReflectionAttribute;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Extensions\BaseExtension;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Tools\ArrayTools;

class Router extends BaseApplicationItem
{
    private array $routes = [];

	public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function cleanAndRegisterRoutes()
    {
        $this->routes = [];

        $extensions = $this->getApp()->getExtensions();

        foreach ($extensions as $extension) {
            $controllers = $extension->getControllers();

            foreach ($controllers as $controller) {
                $this->registerController($controller);
            }
        }
    }

    public function getAllRoutes(){
        return $this->routes;
    }

    private function registerController(BaseController $controller)
    {
        $reflection = new \ReflectionClass($controller);
        $controllerAttributes = $reflection->getAttributes(\YTassy\Tycms\Routing\Annotation\Doc\Controller::class);

        if (count($controllerAttributes) !== 1) {
            throw new TechException(
                sprintf('Controller %s must declare exactly one #[Controller] attribute.', $reflection->getName()),
            );
        }

        $controllerDefinition = $controllerAttributes[0]->newInstance();
        $controllerPath = trim($controllerDefinition->path, '/');

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            $methodAttributes = $reflectionMethod->getAttributes(
                \YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod::class,
            );

            foreach ($methodAttributes as $methodAttribute) {
                // Read the raw arguments so both `where` (used by controllers)
                // and the historical `wherePathParams` name remain supported.
                $definition = $methodAttribute->getArguments();
                $methodPath = $definition['path'] ?? null;
                $methods = $definition['methods'] ?? ['GET'];

                $routeName = $controllerDefinition->name . $definition['name'];

                if (!is_string($methodPath) || !is_array($methods)) {
                    throw new TechException(
                        sprintf('Invalid #[ControllerMethod] attribute on %s::%s().', $reflection->getName(), $reflectionMethod->getName()),
                    );
                }

                $pathParts = array_filter(
                    [$controllerPath, trim($methodPath, '/')],
                    static fn (string $part): bool => $part !== '',
                );
                $path = '/' . implode('/', $pathParts);

                foreach ($methods as $method) {
                    $requestMethod = $method instanceof RequestMethod
                        ? $method
                        : (is_string($method) ? RequestMethod::tryFrom(strtoupper($method)) : null);

                    if ($requestMethod === null) {
                        throw new TechException(
                            sprintf('Invalid HTTP method on %s::%s().', $reflection->getName(), $reflectionMethod->getName()),
                        );
                    }

                    $this->registerRoute($routeName, $requestMethod, $path, $controller,
                        $reflectionMethod->getName(), $definition['where'] ?? null,
                        $controllerDefinition->isApi, withoutCsrf: $controllerDefinition->withoutCsrf);
                }
            }
        }

    }

    public function registerRoute(string $name, RequestMethod $method, string $path,
        BaseController $controller, string $controllerMethod, ?array $where,
            bool $isApi, ?array $routeData = null, bool $withoutCsrf = false)
    {

        $reflection = new \ReflectionClass($controller);

        $reflectionMethod = $reflection->getMethod($controllerMethod);
        $allMethodAttributes = $reflectionMethod->getAttributes();
        $allMethodAttributes = array_map(function(ReflectionAttribute $item) {
            return $item->newInstance();
        }, array_filter($allMethodAttributes, function(ReflectionAttribute $item) {
            return $item->name != \YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod::class;
        }));

        $pathRegex = preg_quote($path);
        $params = [];

        $pathRegex = preg_replace_callback('/\\\{([a-zA-Z0-9_]+)\\\}/', function ($matches) use (&$params, &$where) {
            $param = $matches[1];
            $params[] = $matches[1];

            if ($where !== null && isset($where[$param])) {
                $regex = $where[$param];
                return '(' . $regex . ')';
            }
            return '(.*?)';
        }, $pathRegex);

        $pathRegex = '#^' . $pathRegex . '$#';

        if (isset($this->routes[$name])){
            throw new TechException("La route $name existe déjà dans le router.");
        }

        $this->routes[$name] = [
            'name' => $name,
            'method' => $method,
            'path' => $path,
            'isApi' => $isApi,
            'withoutCsrf' => $withoutCsrf,
            'pathRegex' => $pathRegex,
            'controller' => $controller,
            'controllerMethod' => $controllerMethod,
            'where' => $where ?? [],
            'params'    => $params,
            'attributes' => self::indexAttributeMethod($allMethodAttributes),
            'route_data' => $routeData
        ];

    }

    private static function indexAttributeMethod(array $attributes)
	{
		$res = [];

		foreach($attributes as $item)
		{
            if ($item instanceof ControllerParam1) $val = 'ControllerParam';
            // Un namespace PHP utilise des antislashs, même sous Linux : basename() ne convient pas.
            else $val = (new \ReflectionClass($item))->getShortName();
			$res[$val][] = $item;
		}

		return $res;
	}

    public function resolve(?Request $request = null): ?Route{
        
        $request = $request ?? $this->request;
        $path = $request->path;
        
        foreach($this->routes as $route){
            if($route['method'] === $request->method && preg_match($route['pathRegex'], $path, $matches)){

                array_shift($matches);
                $params = [];

                foreach($route['params'] as $index => $paramName){
                    $params[$paramName] = $matches[$index] ?? null;
                }

                $attributes = $route['attributes'];

                $errors = [];
                $inputs = self::resolveInputsForRoute($request, $attributes['ControllerParam'] ?? [], $errors);
                
                return new Route(
                    name: $route['name'],
                    controller: $route['controller'],
                    controllerMethod: $route['controllerMethod'],
                    isApi: $route['isApi'],
                    params: $params,
                    attributes: $attributes,
                    inputs: $inputs,
                    noTransaction: count($attributes['NoTransaction'] ?? []) > 0,
                    root: count($attributes['Root'] ?? []) > 0,
                    testOnly: count($attributes['TestOnly'] ?? []) > 0,
                    userLogged: count($attributes['UserLogged'] ?? []) > 0,
                    errors: $errors,
                    routeData: $route['route_data'],
                    withoutCsrf: $route['withoutCsrf']
                );
            }
        }

        return null;

    }


    private function resolveInputsForRoute(?Request $request, array $attributesParams, array &$errors){
        $res = [];

        foreach($attributesParams as $attrParam){

            $inputVal = $request->input($attrParam->name, null);
            $errorFormatMessage = [
                'param' => $attrParam->name,
                'message' => $attrParam->errorUserMessage ?? ("Le format du paramètre " . $attrParam->name . " est inccorect.")
            ];

            // Missing ?
            if ($attrParam->required && $inputVal === null)
            {
                $errors[] = [
                    'param' => $attrParam->name,
                    'message' => $attrParam->errorUserMessage ?? ("Le paramètre " . $attrParam->name . " est manquant.")
                ];
                continue;
            }

            // An absent optional parameter is valid as null. In particular,
            // do not run an integer, boolean, or JSON validator on it.
            if ($inputVal === null) {
                $res[$attrParam->name] = null;
                continue;
            }

            // Check Format
            $varType = $attrParam->varType ?? VarType::STRING;

            // Vérifier la forme avant les regex et le décodage : aucun tableau/objet ne doit provoquer un TypeError.
            $validType = match ($varType) {
                VarType::INT => is_int($inputVal) || is_string($inputVal),
                VarType::FLOAT => is_int($inputVal) || is_float($inputVal) || is_string($inputVal),
                VarType::BOOL => is_bool($inputVal) || is_int($inputVal) || is_string($inputVal),
                VarType::JSON, VarType::JSON_ARRAY => is_string($inputVal),
                default => is_scalar($inputVal),
            };

            if (!$validType) {
                $errors[] = $errorFormatMessage;
                continue;
            }

            if ($varType == VarType::INT)
            {
                if (preg_match('/^[1-9][0-9]*$/', $inputVal) !== 1) {
                    $errors[] = $errorFormatMessage;
                    continue;
                }
                
                $res[$attrParam->name] = intval($inputVal);
            }
            else if ($varType == VarType::BOOL)
            {
                $booleanValue = is_string($inputVal) ? strtolower($inputVal) : $inputVal;
                if (!in_array($booleanValue, [true, false, 1, 0, '1', '0', 'true', 'false'], true))
                {
                    $errors[] = $errorFormatMessage;
                    continue;
                }

                $res[$attrParam->name] = in_array($booleanValue, [true, 1, '1', 'true'], true);
            }
            else if ($varType == VarType::FLOAT)
            {
                if (preg_match('/^[0-9]*(\.[0-9]+)?$/', $inputVal) !== 1) {
                    $errors[] = $errorFormatMessage;
                    continue;
                }

                $res[$attrParam->name] = floatval($inputVal);
            }
            else if ($varType == VarType::JSON)
            {
                if (json_validate($inputVal) == false) {
                    $errors[] = $errorFormatMessage;
                    continue;
                }

                $jsonObjectValue = json_decode($inputVal);
                $jsonSchema = $attrParam->getSchema();

                if ($jsonSchema != null) {

                    $validationResult = $this->objectValidator->normalizeAndValidate($jsonObjectValue, $jsonSchema);
                    
                    // Get value
                    $jsonObjectValue = $validationResult->value;

                    if ($validationResult->isValid() == false)
                    {
                        if ($this->app->getConf()->dev){
                            $errors[] = [
                                'param' => $attrParam->name,
                                'message' => $validationResult->getErrorsAsString(),
                            ];
                        } else $errors[] = $errorFormatMessage;
                    }
                }

                $res[$attrParam->name] = $jsonObjectValue;
            }
            else if ($varType == VarType::JSON_ARRAY)
            {
                if (json_validate($inputVal) == false) {
                    $errors[] = $errorFormatMessage;
                    continue;
                }

                $res[$attrParam->name] = json_decode($inputVal, true);
            }
            else
                { // STRING
                $res[$attrParam->name] = $inputVal;
            }

        }

        return $res;
    }


    public function url(string $routeName, array $params = [], array $query = []) {
        $route = $this->routes[$routeName] ?? null;

        if ($route === null) {
            throw new TechException("La route $routeName n'existe pas dans le router.");
        }

        $replacements = [];
        foreach ($route['params'] as $paramName) {
            if (!array_key_exists($paramName, $params)) {
                throw new TechException("Le paramètre d'URL $paramName est manquant pour la route $routeName.");
            }

            $replacements['{' . $paramName . '}'] = rawurlencode((string) $params[$paramName]);
        }

        $url = $replacements === [] ? $route['path'] : strtr($route['path'], $replacements);

        return $query === []
            ? $url
            : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

}
