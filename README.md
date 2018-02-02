# Slim-Twig Wrapper
Simple wrapper object for using Slim with Twig.

## Usage

### In _index.php_:
Index file: `/var/www/html/index.php`
``` php
<?php
require 'vendor/autoload.php';

$app = new \IMP\SlimTwigWrapper();
$app->run();
```

### Routes in _routes.php_:
***It is highly recommended to use a `routes.php` file instead of merely using
`index.php`. This will avoid routing conflicts and keep relevant routes
together, which will help ease maintenance.***

Root routes file: `/var/www/html/routes.php` (recommended) or in the index file, `/var/www/html/index.php`
``` php
<?php

// $app->route('get', '/', function () {  --OR--
$app->route('get', '', function () { //<-- The leading '/' does not make a difference.
  $this->render('home.html', [
    'myVar' => 'abc123',
    'anotherVar' => 999,
  ]);

  // This does the same thing as above, the leading '/' does not make a difference.
  $this->render('/home.html', [
    'myVar' => 'abc123',
    'anotherVar' => 999,
  ]);

  // Injecting HTML.
  $this->write('Forcing HTML in the response.');
});

// Any route allowed in Slim should work.
$app->route('get', '/abc[/{userid}]', function ($args) {
  $userID = isset($args['userid']) ? $args['userid'] : null;
  $this->render('info.html', [
    'userID' => $userID,
  ]);
});

$app->run();
```

### Get input parameters:
``` php
$app->route('get, post', '/blah', function () {
  $blah1 = $this->getParam('input_blah1');
  $blah2 = $this->getParam('input_blah2');
  // getParam() is just a shortcut to $this->request->getParam().
});
```

### Accessing the Request, Response, Twig, and Slim objects, if needed:
``` php
$app->route('post', '/aaa', function () {
  $formInput = $this->request->getParsedBody(); // Get POST data.
  $slimDependencyContainer = $this->slim->getContainer();
  $this->response->write(
    $this->twig->render('aaa/edit.html', [
      'userID' => $formInput['user-id'],
    ])
  );
});
```

### Shortcut to writing to the Response object:
``` php
$app->route('get', '/blah', function () {
  $this->write('Some text goes here!');
  // Since there is a valid Response object at this point, the string is written to it.
  // If there was no valid Response object, the string would be written to the output buffer (PHP's print() function).
  // This is just a shortcut for $this->response->write('Some text goes here!').
});
```

### Dependency Injection:
``` php
$app->addDependency('flashMessenger', function ($container) {
  $myOtherDependency = $container->get('a_different_dependency');
  // Do something with $myOtherDependency ... blah blah
  return new \App\CustomFlashMessenger();
});

$app->route('get', '/aaa', function () {
  // Access any dependency in the container through the Slim object.
  $this->slim->flashMessenger->add('notice', 'Blah blah some text.');
  $this->render('aaa/home.html');
});
```

### Add global Twig variables:
``` php
$app->addGlobal('varname', 'value123');
// This is just a shortcut for $this->twig->addGlobal('varname', 'value123');
```

In the Twig template file:
``` html
<h1>My Awesome Page!</h1>
<p>
  The variable value is {{ varname }}.
</p>
```

### Add middleware for all routes:
``` php
$app->addMiddleware(function ($callNext) {
  $this->write('Middleware: Before content!');
  $callNext(); //<-- Move on to the next middleware or main content.
  $this->write('Middleware: After content!');
});

$app->addMiddleware(function ($callNext) {
  $this->write('This middleware only do stuff before the content. The call to "next" is done automatically if not envoked in this closure.');

  // Direct access to parameters passed in middleware callbacks by Slim:
  // $this->request
  // $this->response
  // $this->next
  // Example:
  //    $this->response->write('Header');
  //    $this->response = $this->next($this->request, $this->response); //<-- Move on to the next middleware or main content.
  //    $this->response->write('Footer');
});
```

### Add middleware for a route group:
The middleware(s) will be called for any route that starts with the specified group route.
``` php
$app->addGroupMiddleware('/accounts', function ($callNext) {
    $this->write('Middleware for the "accounts" sections.');
});

$app->route('get', '/accounts/list', function () {
    $this->render('accounts/list.html');
});

$app->route('get, post', '/account/edit/{id}', function ($args) {
    $data = getAccountDataFromDB($args['id']+);
    if ($this->getParam('save')) {
        // ... update the DB ...
    }
    $this->render('accounts/edit.html', [
        'data' => $data,
    ]);
});
```

### Add middleware for a route:
Calls to `addRouteMiddleware()` will attach the middleware to the last defined route. If no route have yet been defined,
then the middleware is ignored.
The middleware(s) will be called only if the specified route matches exactly.
``` php
$app->route('get', '/recipes/list', function () {
    $this->render('recipes/list.html');
})->addRouteMiddleware(function ($callNext) {
    $this->write('Middleware 1 for the recipe list page.');
})->addRouteMiddleware(function ($callNext) {
    $this->write('Middleware 2 for the recipe list page.');
});
```

### Middleware order of execution:
Similar to Slim's behavior, global middlewares are processed first, then group, then route.
``` php
$app->addMiddleware(function ($next) {
    $this->write('Global middleware 1<br />');
    $next();
    $this->write('Global middleware 11<br />');
})->addMiddleware(function ($next) {
    $this->write('Global middleware 2<br />');
    $next();
    $this->write('Global middleware 22<br />');
});

$app->addGroupMiddleware('test', function ($next) {
    $this->write('Group middleware 1<br />');
    $next();
    $this->write('Group middleware 11<br />');
})->addGroupMiddleware('test', function ($next) {
    $this->write('Group middleware 2<br />');
    $next();
    $this->write('Group middleware 22<br />');
});

$app->route('get', 'test', function () {
    $this->write('TEST!!!<br />');
})->addRouteMiddleware(function ($next) {
    $this->write('Route middleware 1<br />');
    $next();
    $this->write('Route middleware 11<br />');
})->addRouteMiddleware(function ($next) {
    $this->write('Route middleware 2<br />');
    $next();
    $this->write('Route middleware 22<br />');
});
```
The above will output:
```
Global middleware 2
Global middleware 1
Group middleware 2
Group middleware 1
Route middleware 2
Route middleware 1
TEST!!!
Route middleware 11
Route middleware 22
Group middleware 11
Group middleware 22
Global middleware 11
Global middleware 22
```

### Subroot example:
Root routes file: `/var/www/html/routes.php` (recommended) or in `/var/www/html/index.php`  
Subroot routes file: `/var/www/html/accounts/routes.php`

Subroots are **actual** decendant directories of the root directory, not just a
URL path. In this case, `/var/www/html` is the root directory and `accounts`
(`/var/www/html/accounts`) is a subroot.  

All routes in a subroot `routes.php` file will be treated as if having been
prefixed with that directory path. That route file will also only be loaded if
the user goes to that directory through the URL. ***The root route file is not
loaded if the user goes to a subroot directory.*** Basically, subroots can have
their own routes and view files within themselves, and the whole site can be
compartmentalized by subroots. Models may also be secluded, but since they
typically need to be available throughout the site, it's most likely best to
have them relative to the root directory, such as in `/var/www/html/models`.

The subroot directory and the `views` directory within it are passed to the
Twig Loader as valid locations for template files. It will first search in the
root's main directory, then the subroot's directory, then the subroot's `views`
directory, then the root's `views` directory. The root's main directory is
first, to allow explicit requests to templates in the root directory from
a subroot's route.

``` php
<?php
// In /var/www/html/accounts/routes.php:
// Template files that exists:
//   /var/www/html/views/home.html
//   /var/www/html/accounts/views/home.html

// URL: http://mydomain.com/accounts (A subroot route!)
$app->route('get', '', function () {
  // In this example, Twig will search for the template in the following directories, in the following order:
  // 1. /var/www/html
  // 2. /var/www/html/accounts
  // 3. /var/www/html/accounts/views
  // 4. /var/ww/html/views
  $this->render('home.html'); //<-- Uses '/var/www/html/accounts/views/home.html'.
  - OR IF -
  $this->render('/home.html'); //<-- Uses '/var/www/html/accounts/views/home.html'.
  - OR IF -
  $this->render('/accounts/views/home.html'); //<-- Uses '/var/www/html/accounts/views/home.html'.
  - OR IF -
  $this->render('/views/home.html'); //<-- Uses '/var/www/html/views/home.html'.
});
```

### Sample file organization and how the routes will look:

#### Organized By Type

``` php
File structure:
---------------
* css
  * layout.css
* js
  * layout.js
* models
  * CustomFlashMessenger.php
* routes
  * archive.php            <A
  * tools-emulation.php
  * tools-user-info.php    <B
* vendor
  * blahargh
  * slim
  * twig
  * autoload.php
* views                    <
  * archive                <A
    * list.html            <A
    * view.html            <A
  * tools                  <
    * emulation            <
      * search.html        <
    * user-info            <B
      * search.html        <B
      * view.html          <B
  * home.html              <
* composer.json
* index.php

Routes:
-------
// In index.php

// Load in all of our other routes available to the application in the "routes" directory.
// A more performant solution would be to not use glob(), but
// for small apps this is neglible
$routeFiles = (array)glob('routes/*.php');
foreach ($routeFiles as $route) {
    require $route;
}

$app->route('get', '/', function () {
  $this->render('home.html');
});


// In routes/archive.php                                             <A
$app->route('get', '/archive', function () {                         <A
  $this->render('archive/list.html');                                <A
});                                                                  <A

// In routes/tools-user-info.php                                     <B
$app->route('get', '/tools/user-info', function () {                 <B
  $this->render('tools/user-info/search.html');                      <B
});                                                                  <B
$app->route('get', '/tools/user-info/edit/{id}', function ($args) {  <B
  $this->render('tools/user-info/view.html', ['id'=>$args['id']]);   <B
});                                                                  <B
```

#### Organized by Modules (Subroots) - Modular MVC Structure

``` php
File structure:
---------------
* archive                        <A
  * views                        <A
      * list.html                <A
      * view.html                <A
  * routes.php                   <A
* css
  * layout.css
* js
  * layout.js
* models
  * CustomFlashMessenger.php
* tools                          <
  * user-info                    <B
    * views                      <B
      * search.html              <B
      * view.html                <B
    * routes.php                 <B
  * emulation
    * views
      * search.html
    * routes.php
* vendor
  * blahargh
  * slim
  * twig
  * autoload.php
* views
  * home.html
* composer.json
* index.php
* routes.php

Routes:
-------
// In routes.php
$app->route('get', '/', function () {
  $this->render('home.html');
});

// In archive/routes.php                                        <A
$app->route('get', '', function () {                            <A
  $this->render('views/list.html');                             <A
});                                                             <A

// In tools/user-info/routes.php                                <B
$app->route('get', '', function () {                            <B
  $this->render('views/search.html');                           <B
});                                                             <B
$app->route('get', 'edit/{id}', function ($args) {              <B
  $this->render('views/view.html', ['id'=>$args['id']]);        <B
});                                                             <B
```
