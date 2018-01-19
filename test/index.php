<?php
require 'vendor/autoload.php';

// Report ALL errors
error_reporting(E_ALL);

// Turn assertion handling way the hell up to fatal
assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_CALLBACK, function ($script, $line, $message) {
	 throw new \Exception($message);
});

// Set an error handler that catches EVERYTHING PHP may throw at us
// (notices, warnings, etc) so that we can throw them all as strict errors.
set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext) {
	throw new \Exception(strtr(
		'Unhandled PHP Error: {message} at {file}:{line}',
		[
			'{message}' => $errstr,
			'{file}' => $errfile,
			'{line}' => $errline
		]
	));
});


$app = new \IMP\SlimTwigWrapper();

$app->addMiddleware(function($next) {
    $this->write('Global middleware 1<br />');
    $next();
    $this->write('Global middleware 11<br />');
})->addMiddleware(function($next) {
    $this->write('Global middleware 2<br />');
    $next();
    $this->write('Global middleware 22<br />');
});

$app->route('get', '/', function() {
	$this->render('home.html', [
		'title' => 'Home',
	]);
});

$app->addGroupMiddleware('test', function($next) {
    $this->write('Group middleware 1<br />');
    $next();
    $this->write('Group middleware 11<br />');
})->addGroupMiddleware('test', function($next) {
    $this->write('Group middleware 2<br />');
    $next();
    $this->write('Group middleware 22<br />');
});

$app->route('get', 'test', function() {
    $this->write('TEST!!!<br />');
})->addRouteMiddleware(function($next) {
    $this->write('Route middleware 1<br />');
    $next();
    $this->write('Route middleware 11<br />');
})->addRouteMiddleware(function($next) {
    $this->write('Route middleware 2<br />');
    $next();
    $this->write('Route middleware 22<br />');
});

$app->run();
