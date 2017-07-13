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
Root routes file: `/var/www/html/routes.php`  
``` php
<?php

$app->route('get', '/', function() {
  $this->render('home.html', [
    'myVar' => 'abc123',
    'anotherVar' => 999,
  ]);
  
  // This does the same thing as above. Since this is through the root
  // app, the prefix '/' does not make a difference.
  $this->render('/home.html', [
    'myVar' => 'abc123',
    'anotherVar' => 999,
  ]);
  
  // Injecting HTML.
  $this->response->write('Forcing HTML in the response.');
});

// Any route allowed in Slim should work.
$app->route('get', '/abc[/{userid}]', function($args) {
  $userID = isset($args['userid']) ? $args['userid'] : null;
  $this->render('info.html', [
    'userID' => $userID,
  ]);
});

$app->run();
```

### Accessing the Request, Response, and Twig objects, if needed:
``` php
$app->route('post', '/aaa', function() {
  $formInput = $this->request->getParsedBody(); // Get POST data.
  $this->response->write(
    $this->twig->render('aaa/edit.html', [
      'userID' => $formInput['user-id'],
    ])
  );
});
```

### Shortcut to writing to the Response object:
``` php
$app->route('get', '/blah', function() {
  $this->write('Some text goes here!');
  // Since there is a valid Response object at this point, the string is written to it.
  // If there was no valid Response object, the string would be written to the output buffer (PHP's print() function).
  // This is just a shortcut for $this->response->write('Some text goes here!').
});
```

### Dependency Injection:
``` php
$app->addDependency('flashMessenger', function($container) {
  $myOtherDependency = $container->get('a_different_dependency');
  // Do something with $myOtherDependency ... blah blah
  return new \App\CustomFlashMessenger();
});

$app->route('get', '/aaa', function() {
  $this->app->flashMessenger->add('notice', 'Blah blah some text.');
  $this->render('aaa/home.html');
});
```

### Add global Twig variables:
``` php
$app->addGlobal('varname', 'value123');
```

In the Twig template file:
``` html
<h1>My Awesome Page!</h1>
<p>
  The variable value is {{ varname }}.
</p>
```

### Add middleware:
``` php
$app->addMiddleware(function($callNext) {
  $this->write('Middleware: Before content!');
  $callNext(); //<-- Move on to the next middleware or main content.
  $this->write('Middleware: After content!');
});

$app->addMiddleware(function($callNext) {
  $this->write('This middleware only does things before the content. The call to "next" is done automatically if not envoked in this closure.');
  
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

### Subroot example:
Root routes file: `/var/www/html/routes.php` or in `/var/www/html/index.php`  
Subroot routes file: `/var/www/html/accounts/routes.php`

All routes in a subroot route file will be treated as if having been prefixed with that directory name. That route file will also only be loaded if the user goes to that directory through the URL. The root route file is not loaded if the user goes to a subroot directory.

``` php
<?php
// In /var/www/html/accounts/routes.php:

// URL: http://mydomain.com/accounts
$app->route('get', '', function() {
  $this->render('home.html'); //<-- (Subroot view call) Looks for '/var/www/html/accounts/home.html'.
  - OR IF -
  $this->render('/home.html'); //<-- (Root view call) Looks for '/var/www/html/home.html' or '/var/www/html/views/home.html'.
  - OR IF -
  $this->render('/accounts/home.html'); //<-- (Root view call) Looks for '/var/www/html/accounts/home.html' or '/var/www/html/views/accounts/home.html'.
  - OR IF -
  $this->render('accounts/home.html'); //<-- (Subroot view call) Looks for '/var/www/html/accounts/accounts/home.html', which is probably not what you want!
});

// URL: http://mydomain.com/accounts/edit
$app->route('get, post', 'edit', function () {
  $accounts = \Models\SomeObject::getAccounts();
  $this->render('edit.html', [
    'accounts' => $accounts,
  ]);
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

// Load in all of our other routes available to the application.
// A more performant solution would be to not use glob(), but
// for small apps this is neglible
$routeFiles = (array)glob('routes/*.php');
foreach ($routeFiles as $route) {
    require $route;
}

$app->route('get', '/', function() {
  $this->render('home.html');
});


// In routes/archive.php                                             <A
$app->route('get', '/archive', function() {                          <A
  $this->render('archive/list.html');                                <A
});                                                                  <A

// In routes/tools-user-info.php                                     <B
$app->route('get', '/tools/user-info', function() {                  <B
  $this->render('tools/user-info/search.html');                      <B
});                                                                  <B
$app->route('get', '/tools/user-info/edit/{id}', function($args) {   <B
  $this->render('tools/user-info/view.html', ['id'=>$args['id']]);   <B
});                                                                  <B
```

#### Organized by Modules (Subroots)

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
$app->route('get', '/', function() {
  $this->render('home.html');
});

// In archive/routes.php                                        <A
$app->route('get', '', function() {                             <A
  $this->render('views/list.html');                             <A
});                                                             <A

// In tools/user-info/routes.php                                <B
$app->route('get', '', function() {                             <B
  $this->render('views/search.html');                           <B
});                                                             <B
$app->route('get', 'edit/{id}', function($args) {               <B
  $this->render('views/view.html', ['id'=>$args['id']]);        <B
});                                                             <B
```
