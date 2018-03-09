<?php
namespace IMP;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


class SlimTwigWrapper
{
    private $app;
    private $noMoreRoutes = false;       // Flag to determine if subsequent routes should even be loaded.
    private $groupMiddlewares = array(); // Storage for defined group middlewares.
    private $routes = array();           // Store the routes so they can be manipulated prior to calling \Slim\App::run().
    private $lastDefinedRoute = null;    // Store the last defined route, so subsequent calls to addRouteMiddleware know to which route to attach the middleware.
    private $subrootBase = null;         // Store the subroot base, if specified in the constructor.
    private $noMoreRenders = false;      // Flag to disable rendering more templates. This is mainly set after calling redirectTo().
    private $attributes = array();       // Safe storage for variables that will need to be passed around between different callbacks; for example, middleware to route.
    public $twig;
    public $request;       // Changes per route/middleware.
    public $response;      // Changes per route/middleware.
    public $basePath;      // Changes per route.
    public $next;          // Changes per middleware.
    public $wasNextCalled; // Changes per middleware.

    public $server;
    public $realURIDirectory;


    /**
     * $options = [
     *    'documentRootAppend' => 'mysite'       - This will allow having multiple independent sites that sit under one domain server, by specifying a directory in the DOCUMENT_ROOT that will be appended as the new DOCUMENT_ROOT.
     *    'slimObject'         => $slimObject    - If the Slim object (\Slim\App) was already created, it can be used by this wrapper.
     *    'subrootBase'        => 'mysubroots'   - This is used to specify a directory, relative to the root directory, as the location of all subroots.
     * ]
     */
    public function __construct($options)
    {
        $this->server = $this->encode($_SERVER);
        $this->server['ROOT_APPEND'] = '';
        if (!empty($options['documentRootAppend'])) {
            // Allow specifying a directory in the DOCUMENT_ROOT to make into the new DOCUMENT_ROOT. This will allow
            // having multiple independent sites that sit under one domain server.
            // For example:
            //   $_SERVER['DOCUMENT_ROOT'] = '/var/www/html'
            //   $documentRoot = 'mysite'
            //   In this scenario, "mysite" will be the subdirectory to attach, so $this->server['DOCUMENT_ROOT'] will
            //   be set to "/var/www/html/mysite", and other server values will be adjusted accordingly.
            $documentRootAppend = str_replace('\\', '/', trim($options['documentRootAppend']));
            if (substr($documentRootAppend, 0, 1) !== '/') { $documentRootAppend = '/' . $documentRootAppend; }
            $this->server['ROOT_APPEND'] = $documentRootAppend;
            $this->server['DOCUMENT_ROOT'] = realpath($this->server['DOCUMENT_ROOT'] . $documentRootAppend);
            $length = strlen($documentRootAppend);
            if (substr($this->server['REQUEST_URI'], 0, $length) === $documentRootAppend) { $this->server['REQUEST_URI'] = substr($this->server['REQUEST_URI'], $length); }
            if (substr($this->server['SCRIPT_NAME'], 0, $length) === $documentRootAppend) { $this->server['SCRIPT_NAME'] = substr($this->server['SCRIPT_NAME'], $length); }
        }
        if (!empty($options['subrootBase'])) {
            $this->subrootBase = $options['subrootBase'];
            $this->subrootBase = str_replace('\\', '/', trim($this->subrootBase));
            if (substr($this->subrootBase, 0, 1) !== '/') { $this->subrootBase = '/' . $this->subrootBase; }
        }

        $this->server['DOMAIN_URI'] = 'http' . (!empty($this->server['HTTPS']) && $this->server['HTTPS'] === 'on' ? 's' : '') . '://' . $this->server['HTTP_HOST'];
        $this->server['BASE_PATH'] = $this->server['ROOT_APPEND'];
        $this->server['REQUEST_METHOD'] = strtolower($this->server['REQUEST_METHOD']);
        $parts = explode('?', $this->server['REQUEST_URI']);
        $this->server['REQUEST_URI'] = '/' . trim($parts[0], '/*'); //<-- Request URI should be relative to the domain. Remove trailing "*" so user can't access a wildcard route directly.
        if (!isset($this->server['QUERY_STRING'])) { $this->server['QUERY_STRING'] = ''; }

        $this->realURIDirectory = $this->getRealDirectory(); //<-- "/" or "/some/path"

        if (!empty($options['slimObject']) && is_a($options['slimObject'], '\Slim\App')) {
            $this->slim = $options['slimObject'];
            $this->container = $this->slim->getContainer();
        } else {
            $this->container = new \Slim\Container();
            $this->slim = new \Slim\App($this->container);
        }

        // Make sure the "views" directory exists before loading Twig.
        if (!is_dir('views')) {
            mkdir('views');
        }
        // Determine which template directories to load and in what order.
        // Start with the root directory, a template from the root directory can be specified even when calling from
        // a route in a subroot directory.
        $templatePaths = array('');
        // Include the specified subroot base path.
        if ($this->subrootBase) {
            $templatePaths[] = ltrim($this->subrootBase, '/');
        }
        // Prepend template subpath.
        if ($this->realURIDirectory !== '' && $this->realURIDirectory !== '/') {
            $path = ltrim($this->realURIDirectory, '/');
            if ($this->subrootBase) {
                $path = ltrim($this->subrootBase, '/') . '/' . $path;
            }
            $templatePaths[] = $path;
            if (file_exists($path . '/views')) {
                $templatePaths[] = $path . '/views';
            }
        }
        // Lastly, include the root's "views" directory as the last place to check.
        $templatePaths[] = 'views';

        $this->addDependency('twig', function($container) use ($templatePaths) {
            $loader = new \Twig_Loader_Filesystem($templatePaths);
            $twig = new \Twig_Environment($loader, array(
                 'cache' => false, //'.local/twig_cache',
            ));
            return $twig;
        });

        // Store the twig object as a property for easy referencing, if need be.
        $this->twig = $this->slim->getContainer()->get('twig');
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
            if (is_dir($this->server['DOCUMENT_ROOT'] . $this->subrootBase . $check)) {
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
     * Add an attribute that can then be retrieved in another callback.
     * For example, passing a paramenter from a middleware to a route.
     */
    public function addAttribute($name, $value)
    {
        $this->attributes[$name] = $value;
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
    * Define a middleware to use for a group.
    */
    public function addGroupMiddleware($path, $callback)
    {
        if (!isset($this->groupMiddlewares[$path])) { $this->groupMiddlewares[$path] = array(); }
        $this->groupMiddlewares[$path][] = $this->makeMiddlewareCallback($callback);
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
    * Define a middleware to use for the last defined route.
    */
    public function addRouteMiddleware($callback)
    {
        if (!$this->lastDefinedRoute) { return this; }
        $this->lastDefinedRoute->add($this->makeMiddlewareCallback($callback));
        return $this;
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
     * Get an attribute that may have been set from another callback.
     * For example, passing a paramenter from a middleware to a route.
     */
    public function getAttribute($name)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : null;
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
     * Get all input parameters.
     */
    public function getParams()
    {
        if (empty($this->request)) { return array(); }
        return $this->request->getParams();
    }

    /**
     * Get the rendered HTML from a twig template. The actual template file NEEDS to have a ".html" extension.
     * The $toRender parameter does not have to have the ".html" extension. It will be appended if missing.
     * Leading underscore ('_') for template files will be recognized. This can help group template files together if
     * they are in the same directory as the routes.php file.
     */
    public function getRender($toRender, $params = array())
    {
        if (strpos($toRender, ' ') === false) {
            // Append ".html" if the template passed in does not have it.
            if (substr($toRender, -5) !== '.html') { $toRender .= '.html'; }
            // Check for the template as is.
            if ($this->twig->getLoader()->exists($toRender)) {
                return $this->twig->render($toRender, $params);
            }
            // Check if the underscored version exists.
            $_toRender = rtrim(dirname($toRender), '/') . '/_' . basename($toRender);
            if ($this->twig->getLoader()->exists($_toRender)) {
                return $this->twig->render($_toRender, $params);
            }
            // Throw an exception at this point since the template file was not found in any of the specified
            // Twig paths.
            throw new \Exception("Missing template file: {$toRender}");
        } else {
            /** Force using templates for security? **/
            /** return $toRender; **/
            throw new \Exception('Use a view file template (.html) for rendering HTML.');
        }
    }

    /**
     * Get the uploaded files. This is a shortcut for $this->request->getUploadedFiles(), which returns and array with
     * the key being the field name and the value being a Slim\Http\UploadedFile object.
     *
     * Example:
     *    Array
     *    (
     *        [Filedata] => Slim\Http\UploadedFile Object
     *            (
     *                [file] => C:\Users\santos.134\AppData\Local\Temp\1\phpF836.tmp
     *                [name:protected] => test_upload_g.txt
     *                [type:protected] => text/plain
     *                [size:protected] => 1540
     *                [error:protected] => 0
     *                [sapi:protected] => 1
     *                [stream:protected] =>
     *                [moved:protected] =>
     *            )
     *    )
     */
    public function getUploadedFiles()
    {
        if (empty($this->request)) { return array(); }
        return $this->request->getUploadedFiles();
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

    /**
     * Specify a redirection.
     */
    public function redirectTo($uri)
    {
        // If leading with "http://" or "https://", then redirect as is.
        if (substr($uri, 0, 7) === 'http://' || substr($uri, 0, 8) === 'https://') {
            $this->response = $this->response->withRedirect($uri);
        } else {
            // If not leading with a slash ('/'), redirect based off of the
            // realURIDirectory, so it behaves similar to routes.
            if (substr($uri, 0, 1) !== '/' && $this->realURIDirectory !== '/') {
                $uri = $this->realURIDirectory . '/' . $uri;
            }
            // Make sure the URI has a leading slash ('/') before it's appended
            // to the BASE_PATH (since the code above will have a leading slash.
            if (substr($uri, 0, 1) !== '/') { $uri = '/' . $uri; }
            $this->response = $this->response->withRedirect($this->server['BASE_PATH'] . $uri);
        }
        $this->noMoreRenders = true;
    }

    /**
     * Render a twig template or an HTML string.
     */
    public function render($toRender, $params = array())
    {
        if (!$this->noMoreRenders) {
            $this->response->write($this->getRender($toRender, $params));
        }
        return $this;
    }

    /**
     * Define a route.
     */
    public function route($methods, $path, $callback)
    {
        if ($this->noMoreRoutes) { return $this; }
        if (!is_string($path)) { return $this; }

        // Declare the route as coming from the subroot if the path does not
        // have a leading slash ('/') and realURIDirectory is specified.
        if (substr($path, 0, 1) !== '/' && $this->realURIDirectory !== '/') {
            $path = $this->realURIDirectory . '/' . $path;
        }

        // Make sure the path to make into a route has a leading slash ('/')
        // before it is passed in to Slim.
        if (substr($path, 0, 1) !== '/') { $path = '/' . $path; }

        // Trim out the trailing slash '/', otherwise the URI needs to also
        // have the slash for the route to be found.
        // But routes that are just '/' itself, should not be trimmed.
        if ($path !== '/') { $path = rtrim($path, '/'); }

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
            return $wrapper->response;
        };

        $route = $this->slim->map($methods, $path, $responseCall);
        // Check for any group middlewares and store them to this route.
        // They will be attached prior to running \Slim\App::run(), so their
        // order of execution is similar to how Slim does it.
        // Group middleware of an ancestor path will need to be loaded
        // first (which is reverse of just adding them as they are declared),
        // but those within the same path will be loaded similar to Slim.
        $this->groupMiddlewares = array_reverse($this->groupMiddlewares);
        $route->_middlewaresToAttach = array();
        foreach ($this->groupMiddlewares as $path => $middlewareCallbacks) {
            if (substr($path, 0, strlen($path)) === $path) {
                foreach ($middlewareCallbacks as $middlewareCallback) {
                    $route->_middlewaresToAttach[] = $middlewareCallback;
                }
            }
        }
        // Store this route so it can be referenced later.
        $this->routes[implode(',', $methods) . '--' . $path] = $route;
        // Store this route so subsequent calls to addRouteMiddleware know to attach it to this route.
        $this->lastDefinedRoute = $route;

        return $this;
    }

    /**
     * Run the slim process.
     */
    public function run()
    {
        // Add routes if defined in a "routes.php" file in a real directory that is a part of the URL.
        $routesFile = 'routes.php';
        if ($this->realURIDirectory && file_exists("{$this->server['DOCUMENT_ROOT']}{$this->subrootBase}$this->realURIDirectory/$routesFile")) {
            $app = $this;
            // Load routes in all ancestors under the current subroot, not the root one though.
            $parts = explode('/', trim($this->realURIDirectory, '/'));
            $dir = "{$this->server['DOCUMENT_ROOT']}{$this->subrootBase}";
            foreach ($parts as $part) {
                if (file_exists("$dir/$part/$routesFile")) {
                    include "$dir/$part/$routesFile";
                }
                $dir .= "/$part";
            }
            // This one is a single route load. No ancestors.//include "{$this->server['DOCUMENT_ROOT']}{$this->subrootBase}$this->realURIDirectory/$routesFile";
            // Set flag to not load further routes so root routes does not conflict with subroot routes that were just loaded.
            $this->noMoreRoutes = true;
        }

        // Add routes if defined in a "routes.php" file in the base directory.
        if (!$this->noMoreRoutes && file_exists("{$this->server['DOCUMENT_ROOT']}/routes.php")) {
            $app = $this;
            include "{$this->server['DOCUMENT_ROOT']}/routes.php";
        }

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
     * Set/Get settings.
     */
    public function settings($name, $value = null)
    {
        $settings = $this->container->get('settings');
        if ($value === null) {
            return isset($settings[$name]) ? $settings[$name] : null;
        } else {
            $settings[$name] = $value;
            return $this;
        }
    }

    /**
     * Modify the response object to return JSON.
     */
    public function withJson($data, $status = 200, $encodingOptions = 0)
    {
        $this->response = $this->response->withJson($data, $status, $encodingOptions);
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
}
