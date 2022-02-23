<?php
chdir('..');
include('vendor/autoload.php');

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response;

$request = Laminas\Diactoros\ServerRequestFactory::fromGlobals(
    $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
);

$router = new League\Route\Router;

$router->map('GET', '/', 'App\\Controller::index');












$templates = new League\Plates\Engine(dirname(__FILE__).'/../views');

try {
  $response = $router->dispatch($request);
  (new Laminas\HttpHandlerRunner\Emitter\SapiEmitter)->emit($response);
} catch(League\Route\Http\Exception\NotFoundException $e) {
  $response = new Response;
  $response->getBody()->write("Not Found\n");
  (new Laminas\HttpHandlerRunner\Emitter\SapiEmitter)->emit($response->withStatus(404));
} catch(League\Route\Http\Exception\MethodNotAllowedException $e) {
  $response = new Response;
  $response->getBody()->write("Method not allowed\n");
  (new Laminas\HttpHandlerRunner\Emitter\SapiEmitter)->emit($response->withStatus(405));
}
