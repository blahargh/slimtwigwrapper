<?php
namespace IMP;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


class SlimTwigWrapper
{
	private $app;
	
	public $request;   // Changes per route.
	public $response;  // Changes per route.
	public $basePath;  // Changes per route.
	
	public $server;
	public $host;
	public $domainURI;
	public $requestURI;
	public $queryString;
	public $selfURI;
	public $absolutePath;
	public $relativePath;
	public $requestMethod;
	public $realURIDirectory;
	
	
	public function __construct()
	{
		$this->server = $this->encode($_SERVER);
		$parts = explode('?', $this->server['REQUEST_URI']);
		$this->host = $this->server['HTTP_HOST'];
		$this->domainURI = 'http' . (!empty($this->server['HTTPS']) && $this->server['HTTPS'] === 'on' ? 's' : '') . '://' . $this->server['HTTP_HOST'];
		$this->requestURI = '/' . trim($parts[0], '/*'); //<-- Request URI should be relative to the domain. Remove trailing "*" so user can't access a wildcard route directly.
		$this->queryString = isset($this->server['QUERY_STRING']) ? $this->server['QUERY_STRING'] : '';
		$this->selfURI = $this->server['REQUEST_URI'];
		$this->absolutePath = dirname($this->server["SCRIPT_FILENAME"]); #dirname(dirname(__FILE__));
		$this->relativePath = str_replace($this->server['DOCUMENT_ROOT'], '', $this->absolutePath);
		$this->requestMethod = strtolower($this->server['REQUEST_METHOD']);
		$this->realURIDirectory = $this->getRealDirectory(); //<-- "" or "/some/path"
		//$this->basePath =& $this->realURIDirectory;
		
		$container = new \Slim\Container();
		$this->app = new \Slim\App($container);
		
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
		
		$this->addGlobal('host', $this->host);
		$this->addGlobal('domainURI', $this->domainURI);
		$this->addGlobal('requestURI', $this->requestURI);
		$this->addGlobal('selfURI', $this->selfURI);
		$this->addGlobal('relativePath', $this->relativePath);
		$this->addGlobal('realURIDirectory', $this->realURIDirectory);
		
		// Add routes if defined in a "routes.php" file in the base directory.
		if (file_exists("{$this->server['DOCUMENT_ROOT']}$this->relativePath/routes.php")) {
			$app = $this;
			include "{$this->server['DOCUMENT_ROOT']}$this->relativePath/routes.php";
		}
		
		// Add routes if defined in a "routes.php" file in a real directory that is a part of the URL.
		$routesFile = 'routes.php';
		if ($this->realURIDirectory && file_exists("{$this->server['DOCUMENT_ROOT']}$this->realURIDirectory/$routesFile")) {
			$app = $this;
			include "{$this->server['DOCUMENT_ROOT']}$this->realURIDirectory/$routesFile";
		}
	}
	
	/**
	 * Get the existing directory out of a path. This is useful if you want to have index.php include files that are in
	 * a matching base directory.
	 */
	private function getRealDirectory($string = null)
	{
		if ($string === null) { $string = $this->requestURI; }
		
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
		$c = $this->app->getContainer();
		$c[$name] = $callback->bindTo($this->app, $this->app);
	}
	
	/**
	 * Add a global twig variable.
	 */
	public function addGlobal($name, $value)
	{
		$twig = $this->app->getContainer()->get('twig');
		$twig->addGlobal($name, $value);
	}
	
	/**
	 * Run the slim process.
	 */
	public function run()
	{
		$this->app->run();
	}
	
	/**
	 * Define a route.
	 */
	public function route($methods, $path, $callback)
	{
		$path = str_replace('~', $this->realURIDirectory, $path);
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
			$wrapper->basePath = $request->getUri()->getBasePath();
			$wrapper->addGlobal('basePath', $wrapper->basePath);
			$routeCallback($args);
			return $response;
		};
		$this->app->map($methods, $path, $responseCall);
	}
	
	/**
	 * Render a twig template or an HTML string.
	 */
	public function render($toRender, $params = array())
	{
		if (strpos($toRender, ' ') === false && substr($toRender, -5) === '.html') {
			$basePath = dirname($this->server["SCRIPT_FILENAME"]);
			$twig = $this->app->getContainer()->get('twig');
			$toRender = str_replace('~', $this->realURIDirectory, $toRender);
			// First, check if the template is in the real URI directory. If it is, then render that one.
			#if (file_exists("$basePath/{$this->realURIDirectory}/$toRender")) {
			#	$this->response->write($twig->render("$this->realURIDirectory/$toRender", $params));
			#	return;
			#} else {
				// If the template is not in the real URI directory, then check the defined template paths.
				$this->response->write($twig->render($toRender, $params));
				return;
			#}
		} else {
			$this->response->write($toRender);
		}
	}
	
	/**
	 * Specify a redirection.
	 */
	public function redirectTo($uri)
	{
		$this->response->withRedirect($this->basePath . '/instructions');
	}
}