<?php
namespace App;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ServerRequestInterface;

class Controller {

  public function index(ServerRequestInterface $request) {
    $response = new Response;
    \p3k\session_setup();

    $response->getBody()->write(view('index', [
      'title' => 'IndieAuth Rocks!',
    ]));
    return $response;
  }

}
