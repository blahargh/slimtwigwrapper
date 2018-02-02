<?php

$app->addMiddleware(function($next) {
    $this->write('Global middleware 1<br />');
    $next();
    $this->write('Global middleware 11<br />');
})->addMiddleware(function($next) {
    $this->write('Global middleware 2<br />');
    $next();
    $this->write('Global middleware 22<br />');
});

$app->route('get', '', function() {
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
