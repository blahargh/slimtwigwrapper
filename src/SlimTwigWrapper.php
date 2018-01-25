<?php
namespace IMP;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


class SlimTwigWrapper
{
    private $app;
    private $noMoreRoutes = false;       // Flag to determine if subsequent routes should even be loaded.
    private $groupMiddlewares = array(); // Storage for defined group middlewares.
    private $routes = array();           // Store the routes so they can be manipulated prior to calling \Slim\App::run();
    private $lastDefinedRoute = null;    // Store the last defined route, so subsequent calls to addRouteMiddleware know to which route to attach the middleware.

    public $twig;
    public $request;       // Changes per route/middleware.
    public $response;      // Changes per route/middleware.
    public $basePath;      // Changes per route.
    public $next;          // Changes per middleware.
    public $wasNextCalled; // Changes per middleware.

    public $server;
    public $root;
    public $host;
    public $domainURI;
    public $requestURI;
    public $realURIDirectory;
    public $queryString;
    public $selfURI;
    public $absolutePath;
    public $relativePath;
    public $requestMethod;


    public function __construct($documentRootAppend = null)
    {
        $this->server = $this->encode($_SERVER);
        $this->server['ROOT_APPEND'] = '';
        if ($documentRootAppend) {
            // Allow specifying a directory in the DOCUMENT_ROOT to make into the new DOCUMENT_ROOT. This will allow
            // having multiple independent sites that sit under one domain server.
            // For example:
            //   $_SERVER['DOCUMENT_ROOT'] = '/var/www/html'
            //   $documentRoot = 'mysite'
            //   In this scenario, "mysite" will be the subdirectory to attach, so $this->server['DOCUMENT_ROOT'] will
            //   be set to "/var/www/html/mysite", and other server values will be adjusted accordingly.
            $documentRootAppend = str_replace('\\', '/', trim($documentRootAppend));
            if (substr($documentRootAppend, 0, 1) !== '/') { $documentRootAppend = '/' . $documentRootAppend; }
            $this->server['ROOT_APPEND'] = $documentRootAppend;
            $this->server['DOCUMENT_ROOT'] = realpath($this->server['DOCUMENT_ROOT'] . $documentRootAppend);
            $length = strlen($documentRootAppend);
            if (substr($this->server['REQUEST_URI'], 0, $length) === $documentRootAppend) { $this->server['REQUEST_URI'] = substr($this->server['REQUEST_URI'], $length); }
            if (substr($this->server['SCRIPT_NAME'], 0, $length) === $documentRootAppend) { $this->server['SCRIPT_NAME'] = substr($this->server['SCRIPT_NAME'], $length); }
        }

        $this->server['DOMAIN_URI'] = 'http' . (!empty($this->server['HTTPS']) && $this->server['HTTPS'] === 'on' ? 's' : '') . '://' . $this->server['HTTP_HOST'];
        $this->server['BASE_PATH'] = $this->server['ROOT_APPEND'];
        $this->server['REQUEST_METHOD'] = strtolower($this->server['REQUEST_METHOD']);
        $parts = explode('?', $this->server['REQUEST_URI']);
        $this->server['REQUEST_URI'] = '/' . trim($parts[0], '/*'); //<-- Request URI should be relative to the domain. Remove trailing "*" so user can't access a wildcard route directly.
        if (!isset($this->server['QUERY_STRING'])) { $this->server['QUERY_STRING'] = ''; }

        $this->realURIDirectory = $this->getRealDirectory(); //<-- "/" or "/some/path"

        $container = new \Slim\Container();
        $this->slim = new \Slim\App($container);

        // Make sure the "views" directory exists before loading Twig.
        if (!is_dir('views')) {
            mkdir('views');
        }
        $this->addDependency('twig', function($container) {
            $loader = new \Twig_Loader_Filesystem(array('views', ''));
            $twig = new \Twig_Environment($loader, array(
                 'cache' => false, //'.local/twig_cache',
            ));
            return $twig;
        });

        // Store the twig object as a property for easy referencing, if need be.
        $this->twig = $this->slim->getContainer()->get('twig');

        // Prepend template subpath.
        if ($this->realURIDirectory !== '' && $this->realURIDirectory !== '/') {
            $path = ltrim($this->realURIDirectory, '/');
            $this->twig->getLoader()->prependPath($path);
            if (file_exists($path . '/views')) {
                $this->twig->getLoader()->prependPath($path . '/views');
            }
        }

        $this->addGlobal('host', $this->server['HTTP_HOST']);
        $this->addGlobal('domainURI', $this->server['DOMAIN_URI']);
        $this->addGlobal('requestURI', $this->server['REQUEST_URI']);
        $this->addGlobal('basePath', $this->server['BASE_PATH']);
        $this->addGlobal('realURIDirectory', $this->realURIDirectory);

        // Add routes if defined in a "routes.php" file in a real directory that is a part of the URL.
        $routesFile = 'routes.php';
        if ($this->realURIDirectory && file_exists("{$this->server['DOCUMENT_ROOT']}$this->realURIDirectory/$routesFile")) {
            $app = $this;
            include "{$this->server['DOCUMENT_ROOT']}$this->realURIDirectory/$routesFile";
            // Set flag to not load further routes so root routes does not conflict with subroot routes that were just loaded.
            $this->noMoreRoutes = true;
        }

        // Add routes if defined in a "routes.php" file in the base directory.
        if (file_exists("{$this->server['DOCUMENT_ROOT']}/routes.php")) {
            $app = $this;
            include "{$this->server['DOCUMENT_ROOT']}/routes.php";
        }
    }

    /**
     * Get the existing directory out of a path. This is useful if you want to have index.php include files that are in
     * a matching base directory.
     */
    private function getRealDirectory($string = null)
    {
        if ($string === null) { $string = $this->server['REQUEST_URI']; }

        $string = str_replace('\\', '/', trim($string));
        $tokens = explode('/', trim($string, '/'));
        $dir = null;
        foreach ($tokens as $part) {
            #$check = ($dir === null ? $part : $dir . '/' . $part);
            $check = "$dir/$part";
            if (is_dir($this->server['DOCUMENT_ROOT'] . $check)) {
                $dir = $check;
            } else {
                break;
            }
        }
        return $dir;
    }

    /**
    * Convert the passed in callback into a proper middleware callback.
    */
    private function makeMiddlewareCallback($callback)
    {
    $wrapper = $this;
        $middlewareCallback = $callback->bindTo($wrapper);
        $middlewareCall = function ($request, $response, $next) use ($wrapper, $middlewareCallback) {
            $wrapper->request = $request;
            $wrapper->response = $response;
            $wrapper->next = $next;
            $wrapper->wasNextCalled = false;
            $callNext = function () use ($wrapper) {
                $wrapper->response = call_user_func($wrapper->next, $wrapper->request, $wrapper->response);
                $wrapper->wasNextCalled = true;
            };
            $middlewareCallback($callNext);
            if (!$wrapper->wasNextCalled) {
                $callNext();
            }
            return $wrapper->response;
        };
        return $middlewareCall;
    }


    /**
     * This is used to encode a string so that it is safe for print out.
     * This can be overwritten if a different encoding process is desired.
     */
    public function encode($strOrArray)
    {
        if (is_array($strOrArray)) {
            $output = array();
            foreach ($strOrArray as $name => $value) {
                $output[htmlentities($name, ENT_QUOTES | ENT_SUBSTITUTE)] = htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE);
            }
            return $output;
        } else {
            return htmlentities($strOrArray, ENT_QUOTES | ENT_SUBSTITUTE);
        }
    }

    /**
     * Add a dependency injection into the container.
     */
    public function addDependency($name, $callback)
    {
        $c = $this->slim->getContainer();
        $c[$name] = $callback->bindTo($this->slim, $this->slim);

        return $this;
    }

    /**
     * Add a global twig variable.
     */
    public function addGlobal($name, $value)
    {
        $this->twig->addGlobal($name, $value);

        return $this;
    }

    /**
     * Add a middleware.
     */
    public function addMiddleware($callback)
    {
        $this->slim->add($this->makeMiddlewareCallback($callback));
        return $this;
    }

    /**
    * Define a middleware to use for a group.
    */
    public function addGroupMiddleware($path, $callback)
    {
        if (!isset($this->groupMiddlewares[$path])) { $this->groupMiddlewares[$path] = array(); }
        $this->groupMiddlewares[$path][] = $this->makeMiddlewareCallback($callback);
        return $this;
    }

    /**
    * Define a middleware to use for the last defined route.
    */
    public function addRouteMiddleware($callback)
    {
        if (!$this->lastDefinedRoute) { return this; }
        $this->lastDefinedRoute->add($this->makeMiddlewareCallback($callback));
        return $this;
    }

    /**
     * Run the slim process.
     */
    public function run()
    {
        // Attach group middlewares to the proper routes at this point.
        foreach ($this->routes as $route) {
            if (!isset($route->_middlewaresToAttach)) { continue; }
            foreach ($route->_middlewaresToAttach as $middlewareCallback) {
                $route->add($middlewareCallback);
            }
        }
        // Run Slim.
        $this->slim->run();
    }

    /**
     * Define a route.
     */
    public function route($methods, $path, $callback)
    {
        if ($this->noMoreRoutes) { return false; }
        if (!is_string($path)) { return false; }
        if (substr($path, 0, 1) !== '/') { $path = '/' . $path; }

        if ($this->realURIDirectory !== '/') {
            $path = $this->realURIDirectory . $path;
        }

        $methods = explode(',', $methods);
        foreach ($methods as $i => $m) {
            $m = trim($m);
            if ($m == '') { continue; }
            $methods[$i] = strtoupper($m);
        }
        $wrapper = $this;
        $routeCallback = $callback->bindTo($wrapper);
        $responseCall = function ($request, $response, $args) use ($wrapper, $routeCallback) {
            $wrapper->request = $request;
            $wrapper->response = $response;
            $routeCallback($args);
            return $response;
        };

        $route = $this->slim->map($methods, $path, $responseCall);
        // Check for any group middlewares and store them to this route.
        // They will be attached prior to running \Slim\App::run(), so their
        // order of execution is similar to how Slim does it.
        $route->_middlewaresToAttach = array();
        foreach ($this->groupMiddlewares as $path => $middlewareCallbacks) {
            if (substr($path, 0, strlen($path)) === $path) {
                foreach ($middlewareCallbacks as $middlewareCallback) {
                    $route->_middlewaresToAttach[] = $middlewareCallback;
                }
            }
        }
        // Store this route so it can be referenced later.
        $this->routes[$path] = $route;
        // Store this route so subsequent calls to addRouteMiddleware know to attach it to this route.
        $this->lastDefinedRoute = $route;

        return $this;
    }

    /**
     * Render a twig template or an HTML string.
     */
    public function render($toRender, $params = array())
    {
        $this->response->write($this->getRender($toRender, $params));
    }

    /**
     * Get the rendered HTML from a twig template or an HTML string.
     */
    public function getRender($toRender, $params = array())
    {
        if (strpos($toRender, ' ') === false && substr($toRender, -5) === '.html') {
            return $this->twig->render($toRender, $params);
        } else {
            /** Force using templates for security? **/
            /** return $toRender; **/
            throw new \Exception('Use a view file template (.html) for rendering HTML.');
        }
    }

    /**
     * Specify a redirection.
     */
    public function redirectTo($uri)
    {
        $this->response->withRedirect($this->basePath . '/instructions');
    }

    /**
     * Get an input parameter first from PUT, then POST, then GET, and if not found, NULL is returned.
     */
    public function getParam($name)
    {
        if (empty($this->request)) { return null; }
        return $this->request->getParam($name);
    }

     /**
      * Shortcut to write out to the Response object if it exists, to the output buffer otherwise.
      */
     public function write($str)
     {
         if (!empty($this->response) && method_exists($this->response, 'write')) {
            $this->response->write($str);
        } else {
            print $str;
        }
     }


    /**
     * Get environment variables.
     */
    public function getVars()
    {
        return array(
            'realURIDirectory' => $this->realURIDirectory,
        ) + $this->server;
    }
}
