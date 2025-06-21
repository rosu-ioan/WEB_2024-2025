<?php
    define('ROOT', dirname(__DIR__));
    define('SLASH', DIRECTORY_SEPARATOR);

    $folders = ['controllers', 'models', 'views', 'utils', 'templates'];

    function autoload($class) {
        global $folders;

        $vendorAutoloadPath = ROOT . SLASH . 'vendor' . SLASH . 'autoload.php';
        if(file_exists($vendorAutoloadPath)) {
            require_once $vendorAutoloadPath;
        } else {
            throw new Exception("Vendor autoload cannot be found");
        }

        $path = ROOT . SLASH . '<folder>' . SLASH . lcfirst($class) . '.php';
        foreach ($folders as $folder) {
            $filePath = str_replace('<folder>', $folder, $path);
            if(file_exists($filePath)) {
                require_once $filePath;
                return;
            }
        }

        throw new Exception("Class $class not found in any folder.");
    }

    spl_autoload_register('autoload');