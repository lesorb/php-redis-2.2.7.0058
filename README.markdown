# PhpRedis

Base on  PHP extension for interfacing with Redis, version 2.2.7. Add a new object method named 'getMasterByKey'.
This method is prepared for the Redis 3.0+, that can count the number of slot by key, and mathches the Master Server
from redis configuration.

# Base:

phpredis.

Maintainers	Nicolas Favre-Felix Michael Grunder
License		PHP
Description	This extension provides an API for communicating with Redis servers.
Homepage	https://github.com/nicolasff/phpredis/

# Change:
Add a new method getMasterByKey in class Redis.

# Installing/Configuring
-----
{phpdir}/bin/phpize
./configure --with-php-config={phpdir}/bin/php-config
make
make install

vim
{phpconfdir}/php.ini
extension="{php_extensions_dir)/redis.so"

{phpdir}/bin/php -i |grep 'Redis Version'
and show below
Redis Version => 2.2.7.0058

-------------------------------------------------
## Usage

### Class Redis
-----
/redisinit/RedisInit

##### *Example*

Test code here:
~~~~
RedisInit::getInstance()->set('test', 'lesorb');
$return = RedisInit::getInstance()->get('test');
var_dump($return);
~~~~
