<?php
class Config {
  public static $base = 'indieauth.rocks';

  public static $redis = 'tcp://127.0.0.1:6379';

  public static $db = [
    'host' => '127.0.0.1',
    'database' => 'indieauthrocks',
    'username' => 'indieauthrocks',
    'password' => 'indieauthrocks',
  ];

  // When set to true, authentication is bypassed, and you can log in by 
  // entering any email you want in the login form. This is useful when developing
  // this or running it locally.
  public static $skipauth = false;
}
