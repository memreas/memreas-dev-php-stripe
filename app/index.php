<?php
/**
 * This makes our life easier when dealing with paths. Everything is relative
 * to the application root now.
 */
chdir(dirname(__DIR__));


require 'init_autoloader.php';

Zend\Mvc\Application::init(require 'config/application.config.php')->run();
