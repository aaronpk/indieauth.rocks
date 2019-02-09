<?php
include(__DIR__.'/../lib/config.php');

$SCHEME = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
$HOST = $_SERVER['HTTP_HOST'];
$PATH = $_SERVER['REQUEST_URI'];
$URL = $SCHEME.'://'.$HOST.$PATH;


if($PATH == '/500') {
  header('HTTP/1.1 500 Server Error');
}
if($PATH == '/501') {
  header('HTTP/1.1 501 Not Implemented');
}


if($URL == 'http://www.'.Config::$base.'/') {
  permanent_redirect('http://'.Config::$base.'/');
}

if($URL == 'http://'.Config::$base.'/') {
  permanent_redirect('https://'.Config::$base.'/');
}

if($URL == 'https://'.Config::$base.'/') {
  die('IndieAuth test suite and test identities. In progress. <a href="https://github.com/aaronpk/indieauth.rocks">Visit this project on GitHub</a>.');
  #temporary_redirect('https://'.Config::$base.'/all');
}

if($URL == 'https://'.Config::$base.'/github') {
  die('<link href="https://github.com/aaronpk" rel="me authn">');
}

if($URL == 'https://'.Config::$base.'/twitter') {
  die('<link href="https://twitter.com/aaronpk" rel="me authn">');
}

if($URL == 'https://'.Config::$base.'/authn2') {
  echo '<link href="https://github.com/aaronpk" rel="me authn">
<link href="https://twitter.com/aaronpk" rel="me authn">';
  die();
}

if($URL == 'https://'.Config::$base.'/email') {
  echo '<link href="mailto:aaron.parecki@gmail.com" rel="me">
<link href="https://twitter.com/aaronpk" rel="me">';
  die();
}

if($URL == 'https://'.Config::$base.'/pgp') {
  echo '<a href="https://aaronparecki.com/key.txt" rel="pgpkey">pgp key</a>';
  die();
}

if($URL == 'https://'.Config::$base.'/pgp2') {
  echo '<a href="https://aaronparecki.com/key.txt" rel="pgpkey authn">pgp key</a>';
  die();
}

if($URL == 'https://'.Config::$base.'/pgp3') {
  echo '<a href="https://aaronparecki.com/key.txt" rel="pgpkey authn">pgp key</a>
<a href="https://aaronparecki.com/notakey.txt" rel="pgpkey">not this one</a>';
  die();
}

if($URL == 'https://'.Config::$base.'/pgp4') {
  echo '<a href="https://aaronparecki.com/key.txt" rel="pgpkey authn">pgp key</a>
<a href="https://aaronparecki.com/key2.txt" rel="pgpkey authn">or this one</a>';
  die();
}

if($URL == 'https://'.Config::$base.'/pgp5') {
  echo '<a href="https://aaronparecki.com/key.txt" rel="pgpkey">pgp key</a>
<a href="https://aaronparecki.com/key2.txt" rel="pgpkey">or this one</a>
<link href="https://twitter.com/aaronpk" rel="me">';
  die();
}


if($URL == 'https://'.Config::$base.'/all') {
  echo '<link href="https://github.com/aaronpk" rel="me authn">
<link href="https://twitter.com/aaronpk" rel="me authn">
<link href="mailto:aaron@parecki.com" rel="me authn">
<link href="https://aaronparecki.com/key.txt" rel="pgpkey authn">';
  die();
}

if($URL == 'http://account.'.Config::$base.'/') {
  permanent_redirect('https://account.'.Config::$base.'/');
}

if($URL == 'https://account.'.Config::$base.'/') {
  temporary_redirect('http://'.Config::$base.'/account');
}

if($URL == 'http://dreeves.'.Config::$base.'/') {
  temporary_redirect('https://'.Config::$base.'/account');
}

if($URL == 'http://'.Config::$base.'/account') {
  permanent_redirect('http://'.Config::$base.'/account/');
}

if($URL == 'http://header.'.Config::$base.'/') {
  header('Link: </auth>; rel="authorization_endpoint"');
  header('Link: </token>; rel="token_endpoint"', false);
  die();
}

if($URL == 'https://github.'.Config::$base.'/') {
  permanent_redirect('http://'.Config::$base.'/github');
}

if($URL == 'https://twitter.'.Config::$base.'/') {
  permanent_redirect('http://'.Config::$base.'/twitter');
}


if($SCHEME == 'http' && $HOST == Config::$base) {
  permanent_redirect('https://'.$HOST.$PATH);
}

header('Content-type: text/plain');
echo "Scheme: ".$SCHEME."\n";
echo "Host: ".$HOST."\n";
echo "Path: ".$PATH."\n";



function permanent_redirect($url) {
  header('HTTP/1.1 301 Moved Permanently');
  header('Location: '.$url);
  die();
}

function temporary_redirect($url) {
  header('HTTP/1.1 302 Found');
  header('Location: '.$url);
  die();
}
