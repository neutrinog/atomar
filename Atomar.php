<?php

namespace atomar;

use atomar\controller\Install;
use atomar\core\AssetManager;
use atomar\core\Auth;
use atomar\core\AutoLoader;
use atomar\core\HookReceiver;
use atomar\core\Logger;
use atomar\core\ReadOnlyArray;
use atomar\core\Router;
use atomar\core\Templator;
use atomar\exception\HookException;
use atomar\hook\Hook;
use atomar\hook\Libraries;
use atomar\hook\PreBoot;
use model\Extension;

require_once(__DIR__ . '/core/AutoLoader.php');

/**
 * Class Atomar is the center of the Atomar framework.
 * @package atomar
 */
class Atomar {

    /**
     * The site configuration.
     * @var \atomar\core\ReadOnlyArray
     */
    public static $config;
    /**
     * Embodies the site menu system.
     * You may modify this array at any time using the appropriate design pattern.
     * You can see here we started with two empty menus. You may add as many menus as necessary
     * and they will be rendered wherever they are called in the template.
     * @var array
     */
    public static $menu = array(
        'primary_menu' => array(
            'class' => array(
                'nav',
                'navbar-nav'
            )
        ),
        'secondary_menu' => array(
            'class' => array(
                'nav',
                'nav-list',
                'dropdown'
            )
        )
    );
    /**
     * The manifest information
     * @var \atomar\core\ReadOnlyArray
     */
    private static $manifest;
    /**
     * Indicates that the Atomar has been initialized by init()
     * @var bool
     */
    private static $is_initialized = false;

    /**
     * The site application
     * @var null|Extension
     */
    private static $app;

    /**
     * Initializes the system.
     * Parameters may be passed directly as a map or you may specify a configuration file
     * @param string $config_path mixed the path to the site configuration or a map of values
     * @throws \Exception
     */
    public static function init(string $config_path) {
        AutoLoader::register(self::atomar_dir());
        AutoLoader::register(self::atomar_dir(), 1);
        AutoLoader::register(__DIR__ . '/vendor');

        // MVC
        require_once(__DIR__ . '/redbeanphp/rb.php');

        require_once(__DIR__ . '/vendor/autoload.php');

        require_once(__DIR__ . '/core/functions.php');

        // load the configuration
        if (is_string($config_path)) {
            if (file_exists($config_path)) {
                self::$config = new ReadOnlyArray(json_decode(file_get_contents($config_path), true));
            } else {
                throw new \Exception("configuration file is missing");
            }
        } elseif (is_array($config_path)) {
            self::$config = new ReadOnlyArray($config_path);
        } else {
            throw new \Exception("invalid configuration argument");
        }

        // load manifest
        self::$manifest = new ReadOnlyArray(json_decode(file_get_contents(__DIR__ . '/atomar.json'), true));

        if(!is_dir(self::$config['ext_dir'])) {
            mkdir(self::$config['ext_dir'], 0775);
        }

        self::$is_initialized = true;
    }

    /**
     * Returns the path to the root atomar dir
     * @deprecated use atomar_dir instead
     * @return string
     */
    public static function root_dir() {
        return __DIR__;
    }

    /**
     * Returns the path to the root atomar dir
     * @return string
     */
    public static function atomar_dir() {
        return __DIR__;
    }

    /**
     * Returns the namespace of the atomar php framework
     * @return string
     */
    public static function atomar_namespace() {
        return 'atomar';
    }

    /**
     * Checks if debug mode is active
     * @return mixed|null
     */
    public static function debug() {
        return self::$config['debug'];
    }

    /**
     * Returns the path to the root site dir
     * @return string
     */
    public static function site_dir() {
        return dirname($_SERVER["SCRIPT_FILENAME"]);
    }

    /**
     * Returns the information regarding the application
     * @return array
     */
    public static function getAppInfo() {
        return self::$app ? self::$app->export() : null;
    }

    /**
     * Starts up the system!
     * You may optionally specify what app directory to run
     * otherwise the one specified in the config will be used.
     * @param string $app_path An optional path to the application directory
     * @throws \Exception
     */
    public static function run(string $app_path = null) {
        if (self::$is_initialized) {
            if (is_string($app_path)) {
                self::$config['app_dir'] = $app_path;
            } elseif ($app_path != null) {
                throw new \Exception("invalid configuration argument. Expected a string or null");
            }
            self::boot();
        } else {
            throw new \Exception("the core has not been initialized yet. Please run init() first");
        }
    }

    /**
     * Boots up the application
     */
    private static function boot() {
        Templator::init();
        Router::init();

        /**
         * Server static assets
         */
        // TODO: make this configurable so modules can define static directories
        AssetManager::run();

        /**
         * Model
         */
        // prefix model names. e.g. MUser
        define('REDBEAN_MODEL_PREFIX', '\\model\\');
        // set up connection
        $db = self::$config['db'];
        \R::setup('mysql:host=' . $db['host'] . ';dbname=' . $db['name'], $db['user'], $db['password']);
        // test db connection
        if (!\R::testConnection()) {
            $message = <<<HTML
  <p>
    A connection to the database could not be made. Double check your configuration and try again.
  </p>
HTML;
            echo Templator::render_error('DB Connection Failed', $message);
            exit(1);
        }
        unset($db);

        if (self::$config['db']['freeze']) {
            \R::useWriterCache(true);
            // TODO: breaks adding certain things to the db
            \R::freeze(true);
        }

        // set default debug mode
        self::$config['debug'] = boolval(self::get_system('debug', true));

        // log config from further changes
        self::$config->lock();

        if(self::$config['debug']) {
            ini_set('display_errors', 1);
            error_reporting(E_ALL ^ E_NOTICE);
        } else {
            error_reporting(0);
        }

        /**
         * AUTHENTICATION
         */
        Auth::setup(self::$config['auth']);
        Auth::run();

        /**
         * Check if installation is required
         */
        if(!Auth::$user && Install::requiresInstall()) {
            // TODO: rather than routing we should just execute the install controller
            Router::run(array(
                '/.*' => 'atomar\controller\Install',
            ));
            exit;
        }

        /**
         * Autoload app
         *
         */
        AutoLoader::register(self::application_dir());
        AutoLoader::register(self::application_dir(), 1);
        self::$app = self::loadModule(self::application_dir(), self::application_namespace());
        if (!isset(self::$app)) {
            Logger::log_error('Could not load the application from ' . self::application_dir());
            if (self::$config['debug']) {
                set_error('Failed to load the application');
            }
        } else {
            if (vercmp(self::$app->version, self::$app->installed_version) > 0) {
                self::$app->is_update_pending = '1';
            }
            // restrict usage of application if it specifies a supported version of atomar
            if (isset(self::$app->atomar_version) && vercmp(self::$app->atomar_version, self::version()) < 0) {
                self::$app = null;
                Logger::log_error('The application does not support this version of Atomar. Found ' . self::$app->version . ' but expected at least ' . self::version());
                if (self::$config['debug']) {
                    set_error('The application does not support this version of Atomar.');
                }
            }
        }

        self::hook(new PreBoot());
        self::hook(new Libraries());

        Router::run();
    }

    /**
     * Returns the current version of the atomar core.
     *
     * @return string The site version.
     */
    public static function version() {
        return self::$manifest['version'];
    }

    /**
     * Returns the path to the extension directory
     * @return string
     */
    public static function extension_dir() {
        return self::$config['ext_dir'];
    }

    /**
     * Returns the path to the application directory
     * @return string
     */
    public static function application_dir() {
        return rtrim(self::$config['app_dir'], '/') . '/';
    }

    /**
     * Loads an extension from a directory
     * @param string $path The path to the extension directory
     * @param string $slug The extension slug
     * @return null|Extension
     */
    public static function loadModule(string $path, string $slug) {
        if (is_dir($path)) {
            $manifest_file = rtrim($path, '/') . '/atomar.json';
            if (file_exists($manifest_file)) {
                $manifest = json_decode(file_get_contents($manifest_file), true);

                // find existing extension
                $ext = \R::findOne('extension', 'slug=? LIMIT 1', array($slug));

                if (!$ext) {
                    // create new extension
                    $ext = \R::dispense('extension');
                    $ext->slug = $slug;
                    $ext->is_enabled = '0';
                    $ext->is_update_pending = '0';
                }

                // load updated info
                $ext->import($manifest, 'name,description,version,atomar_version,author');
                if(!isset($manifest['atomar_version'])) {
                    $ext->atomar_version = null;
                }
                if (isset($manifest['dependencies'])) {
                    // TODO: eventually we will support specific versions
                    $ext->dependencies = implode(',', array_keys($manifest['dependencies']));
                } else {
                    $ext->dependencies = '';
                }
                try {
                    \R::store($ext);
                } catch(\Exception $e) {
                    Logger::log_error("Failed to load the module " + $slug, $e);
                    return null;
                }

                return $ext;
            }
        }
        return null;
    }

    /**
     * Performs operations on a hook
     * @param Hook $hook the hook to perform
     * @return mixed|null the hook state
     */
    public static function hook(Hook $hook) {
        $hook_name = 'hook' . ltrim(strrchr(get_class($hook), '\\'), '\\');

        // execute hook on atomar
        $state = self::hookModule($hook, 'atomar', self::atomar_dir(), null, null, false);

        // execute hooks on extensions
        $extensions = \R::find('extension', 'is_enabled=\'1\' and slug<>?', array(self::application_namespace()));
        foreach ($extensions as $ext) {
            try {
                $state = self::hookModule($hook, $ext->slug, self::extension_dir() . $ext->slug . DIRECTORY_SEPARATOR, $state, $ext->box(), false);
            } catch (\Exception $e) {
                $notice = 'Could not run hook "' . $hook_name . '" for "' . $ext->slug .'" module';
                Logger::log_error($notice, $e->getMessage());
                if(self::$config['debug']) set_error($notice);
                continue;
            }
        }

        // execute hook on application
        // TRICKY: we re-load the application during hooks in case its settings were changed
        self::$app = self::loadModule(self::application_dir(), self::application_namespace());
        if (self::$app != null && self::$app->is_enabled) { //} && $hook->preProcess(self::$app) !== false) {
            $state = self::hookModule($hook, self::application_namespace(), self::application_dir(), $state, self::$app->box(), false);
        }
        return $hook->postProcess($state);
    }

    /**
     * Executes a hook on a single module.
     *
     * @param Hook $hook the hook that will be executed
     * @param string $namespace the module namespace
     * @param string $directory the module directory
     * @param mixed $state the state object from another hook execution
     * @param Extension $module the module db object if available
     * @param bool $postProcess if true the hook will run it's post process method. Default is true
     * @param bool $force if true the hook will be executed even if the pre process method returns false
     * @return mixed|null the hook state
     * @throws \Exception|HookException Exception is throw if parameters are missing, HookException is throw if the receiver is missing
     */
    public static function hookModule(Hook $hook, string $namespace, string $directory, $state=null, Extension $module=null, $postProcess=true, $force=false) {
        if(!$hook) throw new \Exception('Missing hook parameter');
        if(!$namespace) throw new \Exception('Missing namespace parameter');
        if(!$directory) throw new \Exception('Missing directory parameter');
        $module = isset($module) ? $module : null;

        $hookMethod = 'hook' . ltrim(strrchr(get_class($hook), '\\'), '\\');
        $receiver = $namespace . '\\Hooks';
        $classPath = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . 'Hooks.php';

        if($hook->preProcess($module) === false && $force === false) return $state;
        if(!file_exists($classPath)) throw new HookException('Missing hook receiver in "' . $namespace . '"');

        try {
            require_once($classPath);
            $instance = new $receiver();
            if ($instance instanceof HookReceiver) {
                $result = $instance->$hookMethod($hook->params());
                $state = $hook->process($result, $directory, $namespace, $module, $state);
            } else {
                throw new HookException('Receiver is not an instance of HookReceiver in "' . $namespace . '"');
            }
        } catch (\Error $e) {
            // convert errors to exceptions for simplicity
            throw new \Exception($e->getMessage());
        }

        if($postProcess) {
            $state = $hook->postProcess($state);
        }

        return $state;
    }

    /**
     * Returns the namespace of the application directory
     * @return string
     */
    public static function application_namespace() {
        return self::$config['app_namespace'];
    }

    /**
     * Uninstalls a single extension
     * @param int $id
     * @return bool
     */
    public static function uninstall_extension(int $id) {
        set_warning('This method is deprecated');
        // TODO: use the uninstall hook but only on this extension
        return false;
    }

    /**
     * A hook to run uninstall processes after an extension has been disabled.
     * This is rather dangerous so we are not using this method right now. See uninstall_extension()
     * @deprecated allow the hook to run the uninstall
     */
    public static function hook_uninstall() {
        $extensions = \R::find('extension', 'is_enabled=\'1\'');
        foreach ($extensions as $ext) {
            // uninstall function
            $fun = $ext->slug . '_uninstall';
            $ext_path = self::$config['ext_dir'] . $ext->slug . '/install.php';
            try {
                include_once($ext_path);
            } catch (\Exception $e) {
                Logger::log_warning('Could not load the un-installation file for extension ' . $ext->slug, $e->getMessage());
                continue;
            }
            if (function_exists($fun)) {
                if (call_user_func($fun)) {
                    $ext->installed_version = '';
                    store($ext);
                } else {
                    Logger::log_error('Failed to uninstall extension ' . $ext->slug);
                }
            }
        }
    }

    /**
     * Returns a variable stored in the database or the default value.
     * @param string $key
     * @param string|null $default
     * @return string
     */
    public static function get_variable(string $key, string $default=null) {
        $var = \R::findOne('setting', ' name=? ', array($key));
        if ($var) {
            return $var->value;
        } elseif ($default !== null) {
            // create setting with default value
            $var = \R::dispense('setting');
            $var->name = $key;
            $var->value = $default;
            store($var);
            return $default;
        } else {
            return null;
        }
    }

    /**
     * Sets a variable to be stored in the database
     * @param string $key string
     * @param string $value string if null the variable will be deleted
     * @return bool
     */
    public static function set_variable(string $key, string $value) {
        $var = \R::findOne('setting', ' name=? LIMIT 1', array($key));
        if ($value == null) {
            // delete existing setting
            if ($var->id) {
                try {
                    \R::trash($var);
                    return true;
                } catch (\Exception $err) {
                    return false;
                }
            } else {
                return true;
            }
        } else {
            // store new setting
            if (!$var->id) {
                $var = \R::dispense('setting');
            }
            $var->name = $key;
            $var->value = $value;
            return store($var);
        }
    }

    /**
     * Get the value of a stored system setting. If no setting is found, but a default value is provided
     * the variable will be created and the default value returned.
     * @param string $name the name of the variable
     * @param string $default the default value of the variable.
     * @return string the value of the variable or the default value if specified otherwise null.
     */
    public static function get_system(string $name, string $default = null) {
        $s = \R::findOne('system', ' name=?', array($name));
        if ($s) {
            return $s->value;
        } elseif ($default !== null) {
            // create setting with default value
            $s = \R::dispense('system');
            $s->name = $name;
            $s->value = $default;
            store($s);
            return $default;
        } else {
            return null;
        }
    }

    /**
     * Set the value of a stored system setting. Setting the setting to null  or leaving the value field blank will delete the variable.
     * @param string $name the name of the variable to store.
     * @param string $value the value to be stored in the variable. If no value or null is given the variable will be deleted.
     * @return boolean will return true or false if the variable was successfully created or deleted.
     */
    public static function set_system(string $name, string $value = null) {
        $s = \R::findOne('system', ' name=? LIMIT 1', array($name));
        if ($value == null) {
            // delete existing setting
            if ($s->id) {
                try {
                    \R::trash($s);
                    return true;
                } catch (\Exception $err) {
                    return false;
                }
            } else {
                return true; // setting is already deleted
            }
        } else {
            // store new setting
            if (!$s->id) {
                $s = \R::dispense('system');
            }
            $s->name = $name;
            $s->value = $value;
            return store($s);
        }
    }
}
