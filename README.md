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

### Dependency Injection:
``` php
$app->addDependency('flashMessenger', function() {
  return new \App\CustomFlashMessenger();
});

$app->route('get', '/aaa', function() {
  $this->app->flashMessenger->add('notice', 'Blah blah some text.');
  $this->render('aaa/home.html');
});
```

### Add global Twig variables.
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
