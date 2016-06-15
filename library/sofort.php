<?php
/**
 * @author    Gregor Kralik
 * @copyright 2016 Gregor Kralik
 * @license   GNU LGPL http://www.gnu.org/licenses/lgpl.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php

spl_autoload_register(function ($className) {
    $filename = dirname(__FILE__) . str_replace("\\", "/", $className) . ".php";
    if (file_exists($filename)) {
        include($filename);
        if (class_exists($className)) {
            return true;
        }
    }

    return false;
});